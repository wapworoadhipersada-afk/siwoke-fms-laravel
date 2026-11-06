<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use GdImage;
use Illuminate\Http\UploadedFile;

/**
 * Small helpers shared by the demo seeders: repeatable random choices, Indonesian-sounding names and
 * addresses, and pictures drawn on the fly (so every upload is a different file, as real photos are).
 */
final class DemoSupport
{
    private const FIRST = ['Budi', 'Siti', 'Agus', 'Dewi', 'Rudi', 'Ani', 'Eko', 'Rina', 'Hendra', 'Lestari', 'Dian', 'Fajar', 'Wulan', 'Bayu', 'Maya', 'Andi', 'Yuni', 'Rizky', 'Putri', 'Joko', 'Nur', 'Doni', 'Sari', 'Yoga', 'Tia', 'Imam', 'Ratna', 'Ferry', 'Intan', 'Galih'];

    private const LAST = ['Santoso', 'Wijaya', 'Pratama', 'Hidayat', 'Saputra', 'Kusuma', 'Nugroho', 'Setiawan', 'Rahayu', 'Lestari', 'Permana', 'Wibowo', 'Firmansyah', 'Utami', 'Ramadhan', 'Susanto', 'Halim', 'Purnomo', 'Siregar', 'Gunawan'];

    private const SHOP = ['Toko', 'Warung', 'Apotek', 'Minimarket', 'Kedai', 'Resto', 'Bengkel', 'Laundry', 'Toko Emas', 'Klinik', 'Depot', 'Mart', 'Salon', 'Toko Bangunan', 'Toko Kelontong'];

    private const TRADE = ['Madura', 'Sumber Rejeki', 'Berkah', 'Makmur Jaya', 'Sari Rasa', 'Barokah', 'Maju Terus', 'Cahaya', 'Mitra', 'Sejahtera', 'Abadi', 'Bintang', 'Harapan', 'Ibu Ani', 'Pak Joko', 'Karya Utama', 'Sentosa', 'Lancar', 'Rizky', 'Putra Mandiri', 'Anugerah', 'Prima', 'Nusantara', 'Sehat Sentosa'];

    private const STREET = ['Merdeka', 'Sudirman', 'Ahmad Yani', 'Diponegoro', 'Gatot Subroto', 'Pahlawan', 'Veteran', 'Kenanga', 'Melati', 'Mawar', 'Raya Industri', 'Pemuda', 'Cendrawasih', 'Pattimura', 'Imam Bonjol', 'Hayam Wuruk'];

    /** @var array<string, array{0: int, 1: int, 2: int}> */
    private const PALETTE = [
        'a' => [37, 99, 235], 'b' => [5, 150, 105], 'c' => [217, 119, 6], 'd' => [147, 51, 234], 'e' => [225, 29, 72], 'f' => [8, 145, 178],
    ];

    public static function seed(): void
    {
        mt_srand(20260601);
    }

    /**
     * @template T
     *
     * @param  list<T>  $items
     * @return T
     */
    public static function pick(array $items): mixed
    {
        return $items[mt_rand(0, count($items) - 1)];
    }

    public static function chance(int $percent): bool
    {
        return mt_rand(1, 100) <= $percent;
    }

    public static function personName(): string
    {
        return self::pick(self::FIRST).' '.self::pick(self::LAST);
    }

    public static function merchantName(string $city): string
    {
        return self::pick(self::SHOP).' '.self::pick(self::TRADE).' '.$city;
    }

    public static function address(string $area): string
    {
        return 'Jl. '.self::pick(self::STREET).' No. '.mt_rand(1, 180).', '.$area;
    }

    public static function digits(int $length): string
    {
        $out = '';

        for ($i = 0; $i < $length; $i++) {
            $out .= (string) mt_rand($i === 0 ? 1 : 0, 9);
        }

        return $out;
    }

    public static function phone(): string
    {
        return '08'.self::pick(['11', '12', '13', '21', '22', '52', '53', '57', '77', '78']).self::digits(8);
    }

    /**
     * A site photo: a coloured scene with a caption and some random shapes, different every call.
     */
    public static function photo(string $caption, int $width = 640, int $height = 480): string
    {
        $image = imagecreatetruecolor($width, $height);
        [$r, $g, $b] = self::PALETTE[self::pick(array_keys(self::PALETTE))];
        imagefill($image, 0, 0, (int) imagecolorallocate($image, (int) ($r * 0.35), (int) ($g * 0.35), (int) ($b * 0.35)));

        for ($i = 0; $i < 14; $i++) {
            $color = imagecolorallocate($image, max(0, min(255, $r + mt_rand(-60, 60))), max(0, min(255, $g + mt_rand(-60, 60))), max(0, min(255, $b + mt_rand(-60, 60))));
            imagefilledrectangle($image, mt_rand(0, $width - 40), mt_rand(0, $height - 40), mt_rand(40, $width), mt_rand(40, $height), (int) $color);
        }

        self::label($image, $caption, 18, 30, $height - 24);

        return self::encode($image, 'jpeg');
    }

    /**
     * A signature: a dark scribble on a transparent background.
     */
    public static function signature(int $width = 600, int $height = 300): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagesetthickness($image, 5);
        $ink = (int) imagecolorallocatealpha($image, 20, 20, 60, 0);
        $x = 40;
        $y = mt_rand(100, 200);

        while ($x < $width - 60) {
            $nx = $x + mt_rand(20, 60);
            $ny = max(30, min($height - 30, $y + mt_rand(-90, 90)));
            imageline($image, $x, $y, $nx, $ny, $ink);
            [$x, $y] = [$nx, $ny];
        }

        return self::encode($image, 'png');
    }

    /**
     * A round-cornered-looking avatar with initials.
     */
    public static function avatar(string $name): string
    {
        $size = 400;
        $image = imagecreatetruecolor($size, $size);
        [$r, $g, $b] = self::PALETTE[self::pick(array_keys(self::PALETTE))];
        imagefill($image, 0, 0, (int) imagecolorallocate($image, $r, $g, $b));

        $initials = collect(explode(' ', $name))->take(2)->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))->implode('');
        self::label($image, $initials, 120, 100, 250, true);

        return self::encode($image, 'jpeg');
    }

    public static function upload(string $bytes, string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'demo');
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, null, null, true);
    }

    private static function label(GdImage $image, string $text, int $size, int $x, int $y, bool $bold = false): void
    {
        $font = base_path('vendor/dompdf/dompdf/lib/fonts/'.($bold ? 'DejaVuSans-Bold.ttf' : 'DejaVuSans.ttf'));
        $white = (int) imagecolorallocate($image, 255, 255, 255);

        if (is_file($font)) {
            imagettftext($image, $size, 0, $x, $y, $white, $font, $text);

            return;
        }

        imagestring($image, 5, $x, $y - 14, $text, $white);
    }

    private static function encode(GdImage $image, string $format): string
    {
        ob_start();
        $format === 'png' ? imagepng($image) : imagejpeg($image, null, 88);

        return (string) ob_get_clean();
    }
}
