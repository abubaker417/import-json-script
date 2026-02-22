public function handle()
{
    $data = json_decode(file_get_contents(storage_path('app/quran.json')), true);
    
    $total = count($data['surahs']);
    $this->info("Total surahs to import: {$total}");

    foreach ($data['surahs'] as $index => $surahData) {
        
        // skip already imported
        if (Surah::where('number', $surahData['number'])->exists()) {
            $this->info("Skipping Surah {$surahData['number']} - already imported");
            continue;
        }

        try {
            // upload audio
            $audioContent = file_get_contents($surahData['audio_url']);
            $s3Path = 'audio/surah_' . $surahData['number'] . '.mp3';
            Storage::disk('s3')->put($s3Path, $audioContent);

            $cloudfrontUrl = env('CLOUDFRONT_URL') . '/' . $s3Path;

            $surah = Surah::create([
                'number'       => $surahData['number'],
                'name_arabic'  => $surahData['name_arabic'],
                'name_english' => $surahData['name_english'],
                'audio_url'    => $cloudfrontUrl,
            ]);

            foreach ($surahData['verses'] as $verseData) {
                Verse::create([
                    'surah_id'     => $surah->id,
                    'verse_number' => $verseData['number'],
                    'arabic_text'  => $verseData['arabic_text'],
                    'translation'  => $verseData['translation'],
                ]);
            }

            $this->info("[{$index}/{$total}] Imported Surah {$surahData['number']}");

        } catch (\Exception $e) {
            // log error and continue to next surah instead of stopping
            $this->error("Failed Surah {$surahData['number']}: " . $e->getMessage());
            continue;
        }
    }

    $this->info('Import complete!');
}


# outside of container
nohup php artisan import:quran >> storage/logs/import.log 2>&1 &


# inside container best practices
nohup docker exec -it project_app php artisan import:quran >> /var/log/import.log 2>&1 &



Complete workflow on EC2:
# 1. SSH into server
ssh ubuntu@your-server-ip

# 2. Go to project directory
cd /var/www/your-project

# 3. Run import in background
nohup docker exec project_app php artisan import:quran >> storage/logs/import.log 2>&1 &

# 4. Note the process ID printed
# [1] 12345

# 5. You can now safely disconnect SSH
exit

# 6. SSH back anytime to check progress
tail -f storage/logs/import.log

# 7. Check if still running
ps aux | grep import
