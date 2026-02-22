Step 1 — Create the Job file
php artisan make:job ImportSurahJob

<?php
// app/Jobs/ImportSurahJob.php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use App\Models\Surah;
use App\Models\Verse;

class ImportSurahJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;        // retry 3 times if fails
    public int $timeout = 120;    // 2 minutes max per surah

    public function __construct(public array $surahData) {}

    public function handle()
    {
        // skip if already imported
        if (Surah::where('number', $this->surahData['number'])->exists()) {
            return;
        }

        // upload audio to S3
        $audioContent = file_get_contents($this->surahData['audio_url']);
        $s3Path = 'audio/surah_' . $this->surahData['number'] . '.mp3';
        Storage::disk('s3')->put($s3Path, $audioContent);

        // build cloudfront url
        $cloudfrontUrl = env('CLOUDFRONT_URL') . '/' . $s3Path;

        // save surah
        $surah = Surah::create([
            'number'       => $this->surahData['number'],
            'name_arabic'  => $this->surahData['name_arabic'],
            'name_english' => $this->surahData['name_english'],
            'audio_url'    => $cloudfrontUrl,
        ]);

        // bulk insert all verses in one query instead of one by one
        $versesData = [];
        foreach ($this->surahData['verses'] as $verse) {
            $versesData[] = [
                'surah_id'     => $surah->id,
                'verse_number' => $verse['number'],
                'arabic_text'  => $verse['arabic_text'],
                'translation'  => $verse['translation'],
                'created_at'   => now(),
                'updated_at'   => now(),
            ];
        }
        Verse::insert($versesData);  // one query for all verses
    }

    public function failed(\Exception $e)
    {
        \Log::error('Failed to import Surah ' . $this->surahData['number'] . ': ' . $e->getMessage());
    }
}


Step 2 — Update your command to just dispatch jobs
<?php
// app/Console/Commands/ImportQuranData.php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Jobs\ImportSurahJob;

class ImportQuranData extends Command
{
    protected $signature = 'import:quran';
    protected $description = 'Import Quran data from JSON file';

    public function handle()
    {
        $data = json_decode(
            file_get_contents(storage_path('app/quran.json')), true
        );

        $total = count($data['surahs']);
        $this->info("Dispatching {$total} jobs to queue...");

        foreach ($data['surahs'] as $surahData) {
            ImportSurahJob::dispatch($surahData);
        }

        $this->info('All jobs dispatched! Run queue workers to process.');
    }
}


Step 3 — Setup queue in .env
QUEUE_CONNECTION=database

Step 4 — Create queue table
php artisan queue:table
php artisan migrate

Step 5 — Run the command then start workers
# dispatch all jobs
nohup docker exec project_app php artisan import:quran >> storage/logs/import.log 2>&1 &

# start 5 workers in parallel inside container
nohup docker exec project_app php artisan queue:work --queue=default >> storage/logs/worker1.log 2>&1 &
nohup docker exec project_app php artisan queue:work --queue=default >> storage/logs/worker2.log 2>&1 &
nohup docker exec project_app php artisan queue:work --queue=default >> storage/logs/worker3.log 2>&1 &
nohup docker exec project_app php artisan queue:work --queue=default >> storage/logs/worker4.log 2>&1 &
nohup docker exec project_app php artisan queue:work --queue=default >> storage/logs/worker5.log 2>&1 &


Step 6 — Monitor progress
# check how many jobs remaining in queue
docker exec project_app php artisan queue:monitor

# check worker logs
tail -f storage/logs/worker1.log

# check how many surahs imported in database
docker exec project_app php artisan tinker --execute="echo Surah::count();"


Step 7 — After import is done, stop workers
docker exec project_app php artisan queue:restart
```

---

**Complete flow:**
```
php artisan import:quran
  → dispatches 114 jobs instantly to database queue

5 workers running in parallel
  → worker 1 imports Surah 1
  → worker 2 imports Surah 2
  → worker 3 imports Surah 3
  → worker 4 imports Surah 4
  → worker 5 imports Surah 5
  → all at same time
  → each uses bulk insert for verses
