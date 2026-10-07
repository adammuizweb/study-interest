<?php
declare(strict_types=1);

header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: SAMEORIGIN');
header("Content-Security-Policy: frame-ancestors 'self'");
$page_title = 'Peta Minat Studi';
$page_language = 'id';
$page_class = 'sie-page';

if (!function_exists('stateless_csrf_token')) {
    http_response_code(503);
    $page_class .= ' sie-state-page';
    $content_html = '<main class="sie-state"><span class="sie-logo-mark" aria-hidden="true"></span><p class="sie-kicker">LAYANAN TIDAK TERSEDIA</p><h1>Eksplorasi belum dapat dimulai.</h1><p>Layanan keamanan yang dibutuhkan sedang tidak tersedia. Silakan coba kembali beberapa saat lagi.</p></main>';
    require __DIR__ . '/layout.php';
    return;
}

$csrf = stateless_csrf_token();
$publicId = trim((string)($_GET['session'] ?? ''));
$session = $publicId !== '' ? study_interest_session($pdo, $publicId) : null;

if ($publicId !== '' && $session === null) {
    http_response_code(404);
    $page_class .= ' sie-state-page';
    $content_html = '<main class="sie-state"><span class="sie-logo-mark" aria-hidden="true"></span><p class="sie-kicker">SESI PRIVAT</p><h1>Sesi ini tidak tersedia di browser ini.</h1><p>Tautan hasil dilindungi oleh akses browser yang memulai eksplorasi.</p><a class="sie-button" href="/study-interest/">Mulai eksplorasi baru <span aria-hidden="true">&rarr;</span></a></main>';
} elseif ($session !== null && (string)$session['status'] === 'completed') {
    $snapshot = json_decode((string)$session['result_snapshot_json'], true);
    if (!is_array($snapshot)) {
        http_response_code(503);
        $page_class .= ' sie-state-page';
        $content_html = '<main class="sie-state"><span class="sie-logo-mark" aria-hidden="true"></span><p class="sie-kicker">HASIL TIDAK TERSEDIA</p><h1>Hasil belum dapat ditampilkan.</h1><p>Data hasil sesi ini tidak dapat dibaca. Silakan hubungi pengelola layanan.</p></main>';
    } else {
        $presentation = study_interest_result_presentation($pdo);
        if ((string)$presentation['mode'] === 'hidden') {
            $page_class .= ' sie-state-page';
            $content_html = '<main class="sie-state"><span class="sie-logo-mark" aria-hidden="true"></span><p class="sie-kicker">AKSES HASIL</p><h1>' . study_interest_h((string)$presentation['hidden_title']) . '</h1><p>' . study_interest_h((string)$presentation['hidden_message']) . '</p><a class="sie-button" href="/study-interest/">Kembali <span aria-hidden="true">&rarr;</span></a></main>';
            require __DIR__ . '/layout.php';
            return;
        }
        $page_class .= ' sie-result-page';
        $configuration = study_interest_configuration_from_session($session);
        $assessmentTitle = trim((string)($configuration['title'] ?? $session['test_title'] ?? 'Peta Minat Studi')) ?: 'Peta Minat Studi';
        $page_title = $assessmentTitle;
        $programs = is_array($snapshot['programs'] ?? null) ? $snapshot['programs'] : [];
        $directionLimit = max(1, min(8, (int)($configuration['thresholds']['direct_recommendation_limit'] ?? 3)));
        $recommendations = study_interest_leading_directions($programs, $directionLimit);
        $dimensions = is_array($snapshot['dimensions'] ?? null) ? $snapshot['dimensions'] : [];
        $pathways = is_array($snapshot['professional_pathways'] ?? null) ? $snapshot['professional_pathways'] : [];
        $interpretationItems = is_array($snapshot['interpretations'] ?? null) ? $snapshot['interpretations'] : [];
        $dominantLabels = array_map(static fn(array $dimension): string => (string)($dimension['label'] ?? ''), array_slice($dimensions, 0, 3));
        $dominantLabels = array_values(array_filter($dominantLabels, static fn(string $label): bool => $label !== ''));
        $clarity = (string)($snapshot['profile_clarity']['code'] ?? 'OPEN');
        $clarityText = match ($clarity) {
            'PRACTICALLY_EQUAL', 'MULTIDISCIPLINARY' => (string)($snapshot['result_text']['multidisciplinary'] ?? 'Beberapa bidang layak kamu eksplorasi lebih jauh.'),
            'VERY_CLEAR' => 'Arah minat teratasmu terlihat cukup jelas.',
            'FAIRLY_CLEAR', 'SINGLE_DOMINANT' => 'Kamu sudah memiliki arah awal yang berguna untuk dieksplorasi.',
            default => 'Profil minatmu masih terbuka ke beberapa arah.',
        };
        $scoreName = (string)($snapshot['result_text']['score_name'] ?? 'Indeks kecocokan minat');
        $sectionState = static fn(string $key): string => (string)($presentation['sections'][$key] ?? 'show');
        $renderMask = static function (string $label, ?string $message = null, bool $page = false) use ($presentation): void {
            $message ??= (string)$presentation['section_mask_message'];
            ?><section class="sie-result-mask<?= $page ? ' sie-result-mask-page' : '' ?>" aria-label="<?= study_interest_h($label) ?>">
                <div class="sie-result-mask-preview" aria-hidden="true">
                    <div class="sie-mask-preview-heading"><i></i><i></i></div>
                    <div class="sie-mask-preview-cards"><span><i></i><b></b><small></small></span><span><i></i><b></b><small></small></span><span><i></i><b></b><small></small></span></div>
                    <div class="sie-mask-preview-bars"><span><i></i></span><span><i></i></span><span><i></i></span><span><i></i></span></div>
                </div>
                <div class="sie-result-mask-copy"><p class="sie-kicker">HASIL DISAMARKAN</p><?php if ($page): ?><h1><?= study_interest_h($label) ?></h1><?php else: ?><h2><?= study_interest_h($label) ?></h2><?php endif; ?><p><?= study_interest_h($message) ?></p><?php if ($page): ?><a class="sie-button" href="/study-interest/">Kembali <span aria-hidden="true">&rarr;</span></a><?php endif; ?></div>
            </section><?php
        };
        ob_start();
        ?>
        <main class="sie-results">
            <header class="sie-site-header sie-results-header">
                <a class="sie-logo" href="/study-interest/"><span class="sie-logo-mark" aria-hidden="true"></span><span><?= study_interest_h($assessmentTitle) ?></span></a>
                <span class="sie-private-badge"><span aria-hidden="true"></span> Hasil privat</span>
            </header>
            <?php if ((string)$presentation['mode'] === 'masked'): ?>
                <?php $renderMask((string)$presentation['masked_title'], (string)$presentation['masked_message'], true); ?>
            <?php else: ?>
            <?php if ($sectionState('hero') === 'show'): ?><section class="sie-result-hero" aria-labelledby="sie-result-title">
                <div>
                    <p class="sie-kicker">HASIL EKSPLORASIMU</p>
                    <h1 id="sie-result-title">Kenali pola minat yang paling menonjol.</h1>
                    <p class="sie-result-lead"><?= study_interest_h($clarityText) ?></p>
                </div>
                <div class="sie-signal-map" aria-label="Tiga dimensi minat teratas">
                    <?php foreach ($dominantLabels as $position => $label): ?>
                        <div class="sie-signal sie-signal-<?= $position + 1 ?>"><span>0<?= $position + 1 ?></span><strong><?= study_interest_h($label) ?></strong></div>
                    <?php endforeach; ?>
                </div>
            </section><?php elseif ($sectionState('hero') === 'mask'): ?><?php $renderMask('Ringkasan profil minat'); ?><?php endif; ?>

            <?php if ($sectionState('directions') === 'show'): ?><section class="sie-result-section" aria-labelledby="sie-directions-title">
                <div class="sie-section-heading">
                    <div><p class="sie-kicker">ARAH BERIKUTNYA</p><h2 id="sie-directions-title">Bidang untuk dieksplorasi</h2></div>
                    <p>Gunakan rekomendasi ini untuk mencari tahu isi studi, aktivitas, dan jalur lanjutannya.</p>
                </div>
                <div class="sie-results-grid">
                    <?php if ($recommendations === []): ?>
                        <article class="sie-empty-result">
                            <span class="sie-empty-symbol" aria-hidden="true">+</span>
                            <div><p class="sie-kicker">MASIH TERBUKA</p><h3><?= study_interest_h($snapshot['result_text']['no_dominant_title'] ?? 'Profil Minat Masih Terbuka') ?></h3><p><?= study_interest_h($snapshot['result_text']['no_dominant_body'] ?? '') ?></p></div>
                        </article>
                    <?php else: ?>
                        <?php foreach ($recommendations as $programIndex => $program): ?>
                            <article class="sie-result-card">
                                <span class="sie-rank">0<?= (int)($program['rank'] ?? ($programIndex ?? 0) + 1) ?></span>
                                <h3><?= study_interest_h($program['label'] ?? '') ?></h3>
                                <div class="sie-result-score"><strong><?= number_format((float)($program['score'] ?? 0), 1) ?></strong><span>/100</span></div>
                                <p><?= study_interest_h($program['classification'] ?? '') ?></p>
                            </article>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <p class="sie-score-note"><?= study_interest_h($scoreName) ?> menggambarkan kedekatan pola jawabanmu dengan tiap bidang, bukan peluang diterima atau ukuran kemampuan akademik.</p>
            </section><?php elseif ($sectionState('directions') === 'mask'): ?><?php $renderMask('Arah studi terdekat'); ?><?php endif; ?>

            <?php if ($sectionState('dimensions') === 'show'): ?><section class="sie-dimensions" aria-labelledby="sie-dimensions-title">
                <div class="sie-section-heading sie-section-heading-light">
                    <div><p class="sie-kicker">PETA LENGKAP</p><h2 id="sie-dimensions-title">Delapan dimensi minat</h2></div>
                    <p>Semakin panjang garisnya, semakin konsisten dimensi itu muncul dalam pilihanmu.</p>
                </div>
                <div class="sie-dimension-list">
                    <?php foreach ($dimensions as $index => $dimension): ?>
                        <?php $score = max(0, min(100, (float)($dimension['score'] ?? 0))); ?>
                        <div class="sie-dimension">
                            <div class="sie-dimension-label"><span><?= str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT) ?></span><strong><?= study_interest_h($dimension['label'] ?? '') ?></strong><b><?= number_format($score, 1) ?></b></div>
                            <div class="sie-meter" role="progressbar" aria-label="<?= study_interest_h($dimension['label'] ?? '') ?>" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= study_interest_h($score) ?>"><i style="width:<?= study_interest_h($score) ?>%"></i></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section><?php elseif ($sectionState('dimensions') === 'mask'): ?><?php $renderMask('Peta dimensi minat'); ?><?php endif; ?>

            <?php if ($sectionState('interpretation') === 'show' && $interpretationItems !== []): ?>
                <section class="sie-insight" aria-labelledby="sie-insight-title"><p class="sie-kicker">BACA POLANYA</p><h2 id="sie-insight-title">Ketika beberapa hasil berdekatan</h2><?php foreach ($interpretationItems as $interpretation): ?><p><?= study_interest_h($interpretation) ?></p><?php endforeach; ?></section>
            <?php elseif ($sectionState('interpretation') === 'mask'): ?>
                <?php $renderMask('Interpretasi pola hasil'); ?>
            <?php endif; ?>

            <?php if ($sectionState('pathways') === 'show' && $pathways !== []): ?><?php foreach ($pathways as $pathwayIndex => $pathway): ?>
                <section class="sie-pathway" aria-labelledby="sie-pathway-title-<?= (int)$pathwayIndex ?>"><div class="sie-pathway-marker" aria-hidden="true">P</div><div><p class="sie-kicker"><?= study_interest_h($snapshot['result_text']['professional_pathway'] ?? 'JALUR PROFESI') ?></p><h2 id="sie-pathway-title-<?= (int)$pathwayIndex ?>"><?= study_interest_h($pathway['label'] ?? '') ?></h2><p><?= study_interest_h($snapshot['result_text']['professional_pathway_body'] ?? '') ?></p></div></section>
            <?php endforeach; ?><?php elseif ($sectionState('pathways') === 'mask'): ?><?php $renderMask('Jalur profesi'); ?><?php endif; ?>

            <?php if ($sectionState('next_steps') === 'show'): ?><section class="sie-next-steps" aria-labelledby="sie-next-steps-title">
                <div><p class="sie-kicker">JANGAN BERHENTI DI SKOR</p><h2 id="sie-next-steps-title">Ubah hasil ini menjadi percakapan.</h2></div>
                <p>Cari tahu mata kuliah, jenis aktivitas, dan pengalaman nyata dari bidang yang menarik perhatianmu. Diskusikan hasil ini dengan orang yang memahami perjalanan belajarmu.</p>
                <a class="sie-button sie-button-light" href="/study-interest/">Mulai eksplorasi baru <span aria-hidden="true">&rarr;</span></a>
            </section><?php elseif ($sectionState('next_steps') === 'mask'): ?><?php $renderMask('Langkah berikutnya'); ?><?php endif; ?>
            <?php if ($sectionState('disclaimer') === 'show'): ?><p class="sie-disclaimer"><?= study_interest_h($snapshot['result_text']['disclaimer'] ?? '') ?></p><?php elseif ($sectionState('disclaimer') === 'mask'): ?><?php $renderMask('Catatan hasil'); ?><?php endif; ?>
            <?php endif; ?>
        </main>
        <?php
        $content_html = (string)ob_get_clean();
    }
} elseif ($session !== null) {
    $page_class .= ' sie-assessment-page';
    $configuration = study_interest_configuration_from_session($session);
    $assessmentTitle = trim((string)($configuration['title'] ?? $session['test_title'] ?? 'Peta Minat Studi')) ?: 'Peta Minat Studi';
    $page_title = $assessmentTitle;
    $questions = study_interest_public_questions($pdo, (int)$session['version_id']);
    $answers = study_interest_answer_map($pdo, (int)$session['id']);
    $clientQuestions = [];
    foreach ($questions as $question) {
        $clientQuestions[] = [
            'id' => (int)$question['id'], 'code' => (string)$question['question_code'], 'title' => (string)($question['title'] ?? ''),
            'prompt' => (string)$question['prompt'], 'section' => (string)$question['section_code'], 'section_label' => (string)$question['section_label'],
            'options' => array_map(static fn(array $option): array => ['id' => (int)$option['id'], 'code' => (string)$option['option_code'], 'label' => (string)$option['label']], $question['options']),
        ];
    }
    $clientAnswers = [];
    foreach ($clientQuestions as $question) {
        $selectedCode = $answers[$question['code']] ?? null;
        foreach ($question['options'] as $option) if ($option['code'] === $selectedCode) $clientAnswers[(string)$question['id']] = $option['id'];
    }
    ob_start();
    ?>
    <main class="sie-assessment">
        <header class="sie-assessment-header">
            <span class="sie-logo"><span class="sie-logo-mark" aria-hidden="true"></span><span><?= study_interest_h($assessmentTitle) ?></span></span>
            <div class="sie-progress-wrap">
                <div><span>Progres eksplorasi</span><strong id="sie-progress-label">Pertanyaan 1 dari <?= count($clientQuestions) ?></strong></div>
                <progress id="sie-progress" aria-labelledby="sie-progress-label" max="<?= count($clientQuestions) ?>" value="1"></progress>
            </div>
        </header>
        <section id="sie-question" class="sie-question"></section>
        <footer class="sie-assessment-footer">
            <button id="sie-back" class="sie-button sie-button-quiet" type="button"><span aria-hidden="true">&larr;</span> Kembali</button>
            <span id="sie-save-state" class="sie-save-state" role="status" aria-live="polite" data-state="idle"><i aria-hidden="true"></i> Siap</span>
            <button id="sie-next" class="sie-button" type="button">Lanjut <span aria-hidden="true">&rarr;</span></button>
        </footer>
    </main>
    <?php
    $content_html = (string)ob_get_clean();
    $page_data = ['mode' => 'assessment', 'csrf' => $csrf, 'session' => $publicId, 'questions' => $clientQuestions, 'answers' => $clientAnswers,
        'answerUrl' => '/study-interest/api/answer', 'completeUrl' => '/study-interest/api/complete'];
} else {
    $page_class .= ' sie-landing-page';
    $publishedVersion = study_interest_published_version($pdo);
    $publishedConfiguration = is_array($publishedVersion) ? json_decode((string)$publishedVersion['configuration_json'], true) : null;
    $assessmentTitle = is_array($publishedConfiguration) ? trim((string)($publishedConfiguration['title'] ?? '')) : '';
    $assessmentDescription = is_array($publishedConfiguration) ? trim((string)($publishedConfiguration['description'] ?? '')) : '';
    $assessmentTitle = $assessmentTitle !== '' ? $assessmentTitle : 'Peta Minat Studi';
    $assessmentDescription = $assessmentDescription !== '' ? $assessmentDescription : 'Temukan pola minat, aktivitas, dan cara belajar yang terasa paling dekat denganmu.';
    $questionCount = is_array($publishedVersion) ? max(0, (int)$publishedVersion['expected_question_count']) : 0;
    $estimatedMinutes = max(3, (int)ceil($questionCount / 4));
    $page_title = $assessmentTitle;
    ob_start();
    ?>
    <main class="sie-landing">
        <header class="sie-site-header">
            <a class="sie-logo" href="/study-interest/"><span class="sie-logo-mark" aria-hidden="true"></span><span><?= study_interest_h($assessmentTitle) ?></span></a>
            <span class="sie-time-badge"><?= $questionCount ?> pertanyaan <i></i> sekitar <?= $estimatedMinutes ?> menit</span>
        </header>
        <div class="sie-landing-grid">
            <section class="sie-hero" aria-labelledby="sie-landing-title">
                <p class="sie-kicker">EKSPLORASI, BUKAN UJIAN</p>
                <h1 id="sie-landing-title"><?= study_interest_h($assessmentTitle) ?></h1>
                <p class="sie-hero-lead"><?= study_interest_h($assessmentDescription) ?></p>
                <div class="sie-hero-graphic" aria-hidden="true">
                    <span class="sie-orbit sie-orbit-one"></span><span class="sie-orbit sie-orbit-two"></span><span class="sie-orbit sie-orbit-three"></span>
                    <strong><?= $questionCount ?></strong><small>pilihan untuk<br>membaca pola</small>
                </div>
                <ul class="sie-feature-list"><li><b>Tanpa timer</b><span>Jawab dengan ritmemu sendiri.</span></li><li><b>Tersimpan otomatis</b><span>Lanjutkan di browser yang sama.</span></li><li><b>Tidak ada jawaban benar</b><span>Pilih yang paling menggambarkan dirimu.</span></li></ul>
            </section>

            <form id="sie-start-form" class="sie-start-panel" novalidate>
                <div class="sie-panel-heading"><span>01</span><div><p class="sie-kicker">SEBELUM MULAI</p><h2>Kenalkan dirimu</h2><p>Informasi ini membantu pengelola mengenali hasil eksplorasimu.</p></div></div>
                <div class="sie-fields">
                    <label class="sie-field sie-field-wide"><span>Nama lengkap <b>*</b></span><input id="sie-name" type="text" minlength="2" maxlength="120" autocomplete="name" placeholder="Nama yang biasa kamu gunakan" required></label>
                    <label class="sie-field"><span>Sekolah atau institusi <b>*</b></span><input id="sie-school" type="text" minlength="2" maxlength="191" autocomplete="organization" placeholder="Nama institusi" required></label>
                    <label class="sie-field"><span>Kelas atau tahap saat ini <b>*</b></span><input id="sie-class" type="text" maxlength="40" placeholder="Contoh: Kelas 12" required></label>
                </div>
                <div class="sie-contact-block">
                    <div class="sie-contact-heading"><div><span>Kontak tindak lanjut</span><small>Opsional</small></div><p>Isi hanya jika kamu bersedia dihubungi terkait hasil ini.</p></div>
                    <div class="sie-fields sie-contact-fields">
                        <label class="sie-field"><span>Email</span><input id="sie-email" type="email" maxlength="191" autocomplete="email" placeholder="nama@contoh.com"></label>
                        <label class="sie-field"><span>Nomor telepon</span><input id="sie-phone" type="tel" maxlength="40" autocomplete="tel" placeholder="Nomor yang dapat dihubungi"></label>
                    </div>
                    <label class="sie-check sie-contact-check"><input id="sie-contact-consent" type="checkbox"><span><b>Saya setuju untuk dihubungi.</b> Kontak opsional di atas boleh digunakan untuk menindaklanjuti hasil ini.</span></label>
                </div>
                <label class="sie-check sie-main-consent"><input id="sie-consent" type="checkbox" required><span><b>Saya memahami tujuan eksplorasi ini.</b> Hasilnya bukan diagnosis, tes bakat, atau penilaian kemampuan akademik.</span></label>
                <div class="sie-start-actions"><button id="sie-start" class="sie-button" type="submit" disabled>Mulai eksplorasi <span aria-hidden="true">&rarr;</span></button><p id="sie-message" role="status" aria-live="polite"></p></div>
                <noscript><p class="sie-form-error">JavaScript diperlukan untuk memulai dan menyimpan jawaban eksplorasi ini.</p></noscript>
            </form>
        </div>
    </main>
    <?php
    $content_html = (string)ob_get_clean();
    $page_data = ['mode' => 'landing', 'csrf' => $csrf, 'startUrl' => '/study-interest/api/start',
        'versionId' => is_array($publishedVersion) ? (int)$publishedVersion['id'] : 0,
        'configurationHash' => is_array($publishedVersion) ? (string)$publishedVersion['configuration_hash'] : ''];
}

require __DIR__ . '/layout.php';
