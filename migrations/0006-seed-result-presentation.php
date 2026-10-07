<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $policy = [
        'schema' => 1,
        'mode' => 'full',
        'sections' => [
            'hero' => 'show',
            'directions' => 'show',
            'dimensions' => 'show',
            'interpretation' => 'show',
            'pathways' => 'show',
            'next_steps' => 'show',
            'disclaimer' => 'show',
        ],
        'masked_title' => 'Hasilmu sedang ditinjau',
        'masked_message' => 'Ringkasan hasil belum dibuka untuk peserta. Silakan hubungi pengelola atau konselor untuk informasi lebih lanjut.',
        'hidden_title' => 'Hasil belum dapat ditampilkan',
        'hidden_message' => 'Pengelola belum membuka halaman hasil untuk peserta.',
        'section_mask_message' => 'Bagian ini hanya tersedia melalui pendampingan pengelola atau konselor.',
    ];
    $json = json_encode($policy, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $pdo->prepare('INSERT IGNORE INTO settings (`key`,`value`,`autoload`) VALUES (?,?,1)')
        ->execute(['study_interest_result_presentation', $json]);
};
