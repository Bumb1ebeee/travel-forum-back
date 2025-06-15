<?php

namespace App\Console\Commands;

use App\Models\MediaContent;
use GuzzleHttp\Client;
use Illuminate\Console\Command;

class RefreshYandexDiskUrls extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'media:refresh-urls';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Обновляет истёкшие ссылки с Яндекс.Диска';
    /**
     * Execute the console command.
     */


    public function handle()
    {
        $token = env('YANDEX_DISK_TOKEN');
        $client = new Client();

        $medias = MediaContent::whereNotNull('image_url')->orWhereNotNull('video_url')->get();

        foreach ($medias as $media) {
            $url = $media->image_url ?? $media->video_url ?? $media->music_url;
            if (!str_contains($url, 'yandex')) continue;

            try {
                $filePath = parse_url($url, PHP_URL_QUERY);
                parse_str($filePath, $queryParams);
                $filePath = $queryParams['path'] ?? null;

                if (!$filePath) continue;

                $response = $client->get("https://cloud-api.yandex.net/v1/disk/resources/download",  [
                    'headers' => ['Authorization' => "OAuth {$token}"],
                    'query' => ['path' => $filePath],
                ]);

                $data = json_decode($response->getBody(), true);
                $newUrl = $data['href'] ?? null;

                if ($newUrl) {
                    $media->update([
                        'image_url' => $media->content_type === 'image' ? $newUrl : $media->image_url,
                        'video_url' => $media->content_type === 'video' ? $newUrl : $media->video_url,
                        'music_url' => $media->content_type === 'audio' ? $newUrl : $media->music_url,
                    ]);
                }

            } catch (\Exception $e) {
                continue;
            }
        }

        $this->info('Ссылки успешно обновлены!');
    }
}
