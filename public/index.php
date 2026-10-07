<?php
declare(strict_types=1);

header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: SAMEORIGIN');
header("Content-Security-Policy: frame-ancestors 'self'");
$page_title = 'Study Interest Explorer';
$page_language = 'en';
$page_class = 'sie-page';

if (!function_exists('stateless_csrf_token')) {
    http_response_code(503);
    $page_class .= ' sie-state-page';
    $content_html = '<main class="sie-state"><span class="sie-logo-mark" aria-hidden="true"></span><p class="sie-kicker">SERVICE UNAVAILABLE</p><h1>The assessment cannot start.</h1><p>A required security service is unavailable. Try again shortly.</p></main>';
    require __DIR__ . '/layout.php';
    return;
}

$csrf = stateless_csrf_token();
$publicId = trim((string)($_GET['session'] ?? ''));
$session = $publicId !== '' ? study_interest_session($pdo, $publicId) : null;

if ($publicId !== '' && $session === null) {
    http_response_code(404);
    $page_class .= ' sie-state-page';
    $content_html = '<main class="sie-state"><span class="sie-logo-mark" aria-hidden="true"></span><p class="sie-kicker">PRIVATE SESSION</p><h1>This session is not available in this browser.</h1><p>The session link requires the browser that started the assessment.</p><a class="sie-button" href="/study-interest/">Start a new assessment <span aria-hidden="true">&rarr;</span></a></main>';
} elseif ($session !== null && (string)$session['status'] === 'completed') {
    $snapshot = json_decode((string)$session['result_snapshot_json'], true);
    if (!is_array($snapshot)) {
        http_response_code(503);
        $page_class .= ' sie-state-page';
        $content_html = '<main class="sie-state"><span class="sie-logo-mark" aria-hidden="true"></span><p class="sie-kicker">RESULT UNAVAILABLE</p><h1>This result cannot be displayed.</h1><p>The result snapshot could not be read. Contact the service administrator.</p></main>';
    } else {
        $configuration = study_interest_configuration_from_session($session);
        $legacyConfiguration = (int)($configuration['schema_version'] ?? 1) < 2;
        $page_language = (string)($configuration['locale'] ?? ($legacyConfiguration ? 'id' : 'en'));
        $presentation = study_interest_result_presentation($pdo);
        if ((string)$presentation['mode'] === 'hidden') {
            $page_class .= ' sie-state-page';
            $content_html = '<main class="sie-state"><span class="sie-logo-mark" aria-hidden="true"></span><p class="sie-kicker">' . ($legacyConfiguration ? 'AKSES HASIL' : 'RESULT ACCESS') . '</p><h1>' . study_interest_h((string)$presentation['hidden_title']) . '</h1><p>' . study_interest_h((string)$presentation['hidden_message']) . '</p><a class="sie-button" href="/study-interest/">' . ($legacyConfiguration ? 'Kembali' : 'Back') . ' <span aria-hidden="true">&rarr;</span></a></main>';
            require __DIR__ . '/layout.php';
            return;
        }
        $page_class .= ' sie-result-page';
        $assessmentTitle = trim((string)($configuration['title'] ?? $session['test_title'] ?? 'Study Interest Explorer')) ?: 'Study Interest Explorer';
        $page_title = $assessmentTitle;
        $programs = is_array($snapshot['programs'] ?? null) ? $snapshot['programs'] : [];
        $directionLimit = max(1, min(50, (int)($configuration['thresholds']['direct_recommendation_limit'] ?? 3)));
        $recommendations = study_interest_leading_directions($programs, $directionLimit);
        $dimensions = is_array($snapshot['dimensions'] ?? null) ? $snapshot['dimensions'] : [];
        $pathways = is_array($snapshot['professional_pathways'] ?? null) ? $snapshot['professional_pathways'] : [];
        $interpretationItems = is_array($snapshot['interpretations'] ?? null) ? $snapshot['interpretations'] : [];
        $dominantLabels = array_map(static fn(array $dimension): string => (string)($dimension['label'] ?? ''), array_slice($dimensions, 0, 3));
        $dominantLabels = array_values(array_filter($dominantLabels, static fn(string $label): bool => $label !== ''));
        $clarity = (string)($snapshot['profile_clarity']['code'] ?? 'OPEN');
        $clarityText = match ($clarity) {
            'PRACTICALLY_EQUAL', 'MULTIDISCIPLINARY' => (string)($snapshot['result_text']['multidisciplinary'] ?? ($legacyConfiguration ? 'Beberapa bidang layak kamu eksplorasi lebih jauh.' : '')),
            'VERY_CLEAR' => (string)($snapshot['result_text']['very_clear'] ?? ($legacyConfiguration ? 'Arah minat teratasmu terlihat cukup jelas.' : '')),
            'FAIRLY_CLEAR', 'SINGLE_DOMINANT' => (string)($snapshot['result_text']['fairly_clear'] ?? ($legacyConfiguration ? 'Kamu sudah memiliki arah awal yang berguna untuk dieksplorasi.' : '')),
            default => (string)($snapshot['result_text']['open_profile'] ?? ($legacyConfiguration ? 'Profil minatmu masih terbuka ke beberapa arah.' : '')),
        };
        $scoreName = (string)($snapshot['result_text']['score_name'] ?? 'Indeks kecocokan minat');
        $sectionState = static fn(string $key): string => (string)($presentation['sections'][$key] ?? 'show');
        $renderMask = static function (string $label, ?string $message = null, bool $page = false) use ($presentation, $legacyConfiguration): void {
            $message ??= (string)$presentation['section_mask_message'];
            ?><section class="sie-result-mask<?= $page ? ' sie-result-mask-page' : '' ?>" aria-label="<?= study_interest_h($label) ?>">
                <div class="sie-result-mask-preview" aria-hidden="true">
                    <div class="sie-mask-preview-heading"><i></i><i></i></div>
                    <div class="sie-mask-preview-cards"><span><i></i><b></b><small></small></span><span><i></i><b></b><small></small></span><span><i></i><b></b><small></small></span></div>
                    <div class="sie-mask-preview-bars"><span><i></i></span><span><i></i></span><span><i></i></span><span><i></i></span></div>
                </div>
                <div class="sie-result-mask-copy"><p class="sie-kicker"><?= $legacyConfiguration ? 'HASIL DISAMARKAN' : 'MASKED RESULT' ?></p><?php if ($page): ?><h1><?= study_interest_h($label) ?></h1><?php else: ?><h2><?= study_interest_h($label) ?></h2><?php endif; ?><p><?= study_interest_h($message) ?></p><?php if ($page): ?><a class="sie-button" href="/study-interest/"><?= $legacyConfiguration ? 'Kembali' : 'Back' ?> <span aria-hidden="true">&rarr;</span></a><?php endif; ?></div>
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
                     <p class="sie-kicker"><?= study_interest_h((string)($snapshot['result_text']['hero_kicker'] ?? ($legacyConfiguration ? 'HASIL EKSPLORASIMU' : 'YOUR RESULT'))) ?></p>
                     <h1 id="sie-result-title"><?= study_interest_h((string)($snapshot['result_text']['hero_title'] ?? ($legacyConfiguration ? 'Kenali pola minat yang paling menonjol.' : $assessmentTitle))) ?></h1>
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
                     <div><p class="sie-kicker"><?= study_interest_h((string)($snapshot['result_text']['directions_kicker'] ?? ($legacyConfiguration ? 'ARAH BERIKUTNYA' : 'DIRECTIONS'))) ?></p><h2 id="sie-directions-title"><?= study_interest_h((string)($snapshot['result_text']['directions_title'] ?? ($legacyConfiguration ? 'Bidang untuk dieksplorasi' : 'Study directions'))) ?></h2></div>
                     <p><?= study_interest_h((string)($snapshot['result_text']['directions_body'] ?? ($legacyConfiguration ? 'Gunakan rekomendasi ini untuk mencari tahu isi studi, aktivitas, dan jalur lanjutannya.' : ''))) ?></p>
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
                 <p class="sie-score-note"><?= study_interest_h($legacyConfiguration ? $scoreName . ' menggambarkan kedekatan pola jawabanmu dengan tiap bidang, bukan peluang diterima atau ukuran kemampuan akademik.' : $scoreName) ?></p>
            </section><?php elseif ($sectionState('directions') === 'mask'): ?><?php $renderMask('Arah studi terdekat'); ?><?php endif; ?>

            <?php if ($sectionState('dimensions') === 'show'): ?><section class="sie-dimensions" aria-labelledby="sie-dimensions-title">
                <div class="sie-section-heading sie-section-heading-light">
                     <div><p class="sie-kicker"><?= study_interest_h((string)($snapshot['result_text']['dimensions_kicker'] ?? ($legacyConfiguration ? 'PETA LENGKAP' : 'DIMENSIONS'))) ?></p><h2 id="sie-dimensions-title"><?= study_interest_h((string)($snapshot['result_text']['dimensions_title'] ?? ($legacyConfiguration ? 'Delapan dimensi minat' : 'Interest dimensions'))) ?></h2></div>
                     <p><?= study_interest_h((string)($snapshot['result_text']['dimensions_body'] ?? ($legacyConfiguration ? 'Semakin panjang garisnya, semakin konsisten dimensi itu muncul dalam pilihanmu.' : ''))) ?></p>
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
                 <section class="sie-insight" aria-labelledby="sie-insight-title"><p class="sie-kicker"><?= study_interest_h((string)($snapshot['result_text']['interpretation_kicker'] ?? ($legacyConfiguration ? 'BACA POLANYA' : 'INTERPRETATION'))) ?></p><h2 id="sie-insight-title"><?= study_interest_h((string)($snapshot['result_text']['interpretation_title'] ?? ($legacyConfiguration ? 'Ketika beberapa hasil berdekatan' : 'Reading the pattern'))) ?></h2><?php foreach ($interpretationItems as $interpretation): ?><p><?= study_interest_h($interpretation) ?></p><?php endforeach; ?></section>
            <?php elseif ($sectionState('interpretation') === 'mask'): ?>
                <?php $renderMask('Interpretasi pola hasil'); ?>
            <?php endif; ?>

            <?php if ($sectionState('pathways') === 'show' && $pathways !== []): ?><?php foreach ($pathways as $pathwayIndex => $pathway): ?>
                <section class="sie-pathway" aria-labelledby="sie-pathway-title-<?= (int)$pathwayIndex ?>"><div class="sie-pathway-marker" aria-hidden="true">P</div><div><p class="sie-kicker"><?= study_interest_h($snapshot['result_text']['professional_pathway'] ?? 'JALUR PROFESI') ?></p><h2 id="sie-pathway-title-<?= (int)$pathwayIndex ?>"><?= study_interest_h($pathway['label'] ?? '') ?></h2><p><?= study_interest_h($snapshot['result_text']['professional_pathway_body'] ?? '') ?></p></div></section>
            <?php endforeach; ?><?php elseif ($sectionState('pathways') === 'mask'): ?><?php $renderMask('Jalur profesi'); ?><?php endif; ?>

            <?php if ($sectionState('next_steps') === 'show'): ?><section class="sie-next-steps" aria-labelledby="sie-next-steps-title">
                 <div><p class="sie-kicker"><?= study_interest_h((string)($snapshot['result_text']['next_steps_kicker'] ?? ($legacyConfiguration ? 'JANGAN BERHENTI DI SKOR' : 'NEXT STEPS'))) ?></p><h2 id="sie-next-steps-title"><?= study_interest_h((string)($snapshot['result_text']['next_steps_title'] ?? ($legacyConfiguration ? 'Ubah hasil ini menjadi percakapan.' : 'Continue exploring'))) ?></h2></div>
                 <p><?= study_interest_h((string)($snapshot['result_text']['next_steps_body'] ?? ($legacyConfiguration ? 'Cari tahu mata kuliah, jenis aktivitas, dan pengalaman nyata dari bidang yang menarik perhatianmu. Diskusikan hasil ini dengan orang yang memahami perjalanan belajarmu.' : ''))) ?></p>
                 <a class="sie-button sie-button-light" href="/study-interest/"><?= study_interest_h((string)($snapshot['result_text']['start_over_label'] ?? ($legacyConfiguration ? 'Mulai eksplorasi baru' : 'Start again'))) ?> <span aria-hidden="true">&rarr;</span></a>
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
    $legacyConfiguration = (int)($configuration['schema_version'] ?? 1) < 2;
    $page_language = (string)($configuration['locale'] ?? ($legacyConfiguration ? 'id' : 'en'));
    $publicCopy = is_array($configuration['public_copy'] ?? null) ? $configuration['public_copy'] : [];
    if ($legacyConfiguration) $publicCopy += [
        'progress_label' => 'Progres eksplorasi', 'progress_item_label' => 'Pertanyaan', 'progress_of_label' => 'dari',
        'section_prefix' => 'BAGIAN', 'back_label' => 'Kembali', 'next_label' => 'Lanjut', 'complete_label' => 'Lihat hasil',
        'ready_label' => 'Siap', 'saving_label' => 'Menyimpan...', 'saved_label' => 'Tersimpan',
        'save_error' => 'Jawaban tidak dapat disimpan. Periksa koneksimu dan coba lagi.', 'preparing_label' => 'Menyusun hasil...',
    ];
    $assessmentTitle = trim((string)($configuration['title'] ?? $session['test_title'] ?? 'Study Interest Explorer')) ?: 'Study Interest Explorer';
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
    $progressText = isset($publicCopy['progress_item_label'])
        ? (string)$publicCopy['progress_item_label'] . ' 1 ' . (string)($publicCopy['progress_of_label'] ?? '/') . ' ' . count($clientQuestions)
        : '1 / ' . count($clientQuestions);
    ob_start();
    ?>
    <main class="sie-assessment">
        <header class="sie-assessment-header">
            <span class="sie-logo"><span class="sie-logo-mark" aria-hidden="true"></span><span><?= study_interest_h($assessmentTitle) ?></span></span>
            <div class="sie-progress-wrap">
                <div><span><?= study_interest_h((string)($publicCopy['progress_label'] ?? 'Progress')) ?></span><strong id="sie-progress-label"><?= study_interest_h($progressText) ?></strong></div>
                <progress id="sie-progress" aria-labelledby="sie-progress-label" max="<?= count($clientQuestions) ?>" value="1"></progress>
            </div>
        </header>
        <section id="sie-question" class="sie-question"></section>
        <footer class="sie-assessment-footer">
            <button id="sie-back" class="sie-button sie-button-quiet" type="button"><span aria-hidden="true">&larr;</span> <?= study_interest_h((string)($publicCopy['back_label'] ?? 'Back')) ?></button>
            <span id="sie-save-state" class="sie-save-state" role="status" aria-live="polite" data-state="idle"><i aria-hidden="true"></i> <?= study_interest_h((string)($publicCopy['ready_label'] ?? 'Ready')) ?></span>
            <button id="sie-next" class="sie-button" type="button"><?= study_interest_h((string)($publicCopy['next_label'] ?? 'Next')) ?> <span aria-hidden="true">&rarr;</span></button>
        </footer>
    </main>
    <?php
    $content_html = (string)ob_get_clean();
    $page_data = ['mode' => 'assessment', 'csrf' => $csrf, 'session' => $publicId, 'questions' => $clientQuestions, 'answers' => $clientAnswers, 'copy' => $publicCopy,
        'answerUrl' => '/study-interest/api/answer', 'completeUrl' => '/study-interest/api/complete'];
} else {
    $page_class .= ' sie-landing-page';
    $publishedVersion = study_interest_published_version($pdo);
    if ($publishedVersion === null) {
        http_response_code(503);
        $page_class .= ' sie-state-page';
        $content_html = '<main class="sie-state"><span class="sie-logo-mark" aria-hidden="true"></span><p class="sie-kicker">NO LIVE ASSESSMENT</p><h1>An assessment is not available yet.</h1><p>An administrator must review and publish an assessment before participants can begin.</p></main>';
        require __DIR__ . '/layout.php';
        return;
    }
    $publishedConfiguration = is_array($publishedVersion) ? json_decode((string)$publishedVersion['configuration_json'], true) : null;
    $publishedConfiguration = is_array($publishedConfiguration) ? $publishedConfiguration : [];
    $genericPublished = (int)($publishedConfiguration['schema_version'] ?? 1) >= 2;
    if (!$genericPublished) {
        $publishedConfiguration['public_copy'] = [
            'landing_kicker' => 'EKSPLORASI, BUKAN UJIAN', 'intake_kicker' => 'SEBELUM MULAI', 'question_count_suffix' => 'pertanyaan', 'graphic_label' => 'pilihan untuk membaca pola', 'duration_prefix' => 'sekitar', 'duration_suffix' => 'menit',
            'feature_one_title' => 'Tanpa timer', 'feature_one_body' => 'Jawab dengan ritmemu sendiri.',
            'feature_two_title' => 'Tersimpan otomatis', 'feature_two_body' => 'Lanjutkan di browser yang sama.',
            'feature_three_title' => 'Tidak ada jawaban benar', 'feature_three_body' => 'Pilih yang paling menggambarkan dirimu.',
            'progress_label' => 'Progres eksplorasi', 'progress_item_label' => 'Pertanyaan', 'progress_of_label' => 'dari', 'section_prefix' => 'BAGIAN',
            'back_label' => 'Kembali', 'next_label' => 'Lanjut', 'complete_label' => 'Lihat hasil', 'ready_label' => 'Siap',
            'saving_label' => 'Menyimpan...', 'saved_label' => 'Tersimpan', 'save_error' => 'Jawaban tidak dapat disimpan. Periksa koneksimu dan coba lagi.',
            'preparing_label' => 'Menyiapkan ruang eksplorasimu...', 'start_label' => 'Mulai eksplorasi',
        ];
        $publishedConfiguration['intake'] = [
            'heading' => 'Kenalkan dirimu', 'introduction' => 'Informasi ini membantu pengelola mengenali hasil eksplorasimu.', 'privacy_body' => '', 'privacy_url' => '',
            'assessment_consent_label' => 'Saya memahami tujuan eksplorasi ini. Hasilnya bukan diagnosis, tes bakat, atau penilaian kemampuan akademik.',
            'contact_heading' => 'Kontak tindak lanjut', 'contact_introduction' => 'Isi hanya jika kamu bersedia dihubungi terkait hasil ini.', 'contact_consent_label' => 'Saya setuju untuk dihubungi. Kontak opsional di atas boleh digunakan untuk menindaklanjuti hasil ini.',
            'fields' => [
                'name' => ['enabled' => true, 'required' => true, 'label' => 'Nama lengkap', 'placeholder' => 'Nama yang biasa kamu gunakan', 'help' => ''],
                'school' => ['enabled' => true, 'required' => true, 'label' => 'Sekolah atau institusi', 'placeholder' => 'Nama institusi', 'help' => ''],
                'class_level' => ['enabled' => true, 'required' => true, 'label' => 'Kelas atau tahap saat ini', 'placeholder' => 'Contoh: Kelas 12', 'help' => ''],
                'email' => ['enabled' => true, 'required' => false, 'label' => 'Email', 'placeholder' => 'nama@contoh.com', 'help' => ''],
                'phone' => ['enabled' => true, 'required' => false, 'label' => 'Nomor telepon', 'placeholder' => 'Nomor yang dapat dihubungi', 'help' => ''],
            ],
        ];
    }
    $page_language = (string)($publishedConfiguration['locale'] ?? ($genericPublished ? 'en' : 'id'));
    $publicCopy = is_array($publishedConfiguration['public_copy'] ?? null) ? $publishedConfiguration['public_copy'] : [];
    $intake = is_array($publishedConfiguration['intake'] ?? null) ? $publishedConfiguration['intake'] : [];
    $intakeFields = study_interest_intake_fields($publishedConfiguration);
    $identityFields = array_filter($intakeFields, static fn(array $field, string $key): bool => !in_array($key, ['email', 'phone'], true) && !empty($field['enabled']), ARRAY_FILTER_USE_BOTH);
    $contactFields = array_filter($intakeFields, static fn(array $field, string $key): bool => in_array($key, ['email', 'phone'], true) && !empty($field['enabled']), ARRAY_FILTER_USE_BOTH);
    $assessmentTitle = trim((string)($publishedConfiguration['title'] ?? ''));
    $assessmentDescription = trim((string)($publishedConfiguration['description'] ?? ''));
    $assessmentTitle = $assessmentTitle !== '' ? $assessmentTitle : 'Study Interest Explorer';
    $assessmentDescription = $assessmentDescription !== '' ? $assessmentDescription : 'Temukan pola minat, aktivitas, dan cara belajar yang terasa paling dekat denganmu.';
    $questionCount = is_array($publishedVersion) ? max(0, (int)$publishedVersion['expected_question_count']) : 0;
    $estimatedMinutes = max(3, (int)ceil($questionCount / 4));
    $page_title = $assessmentTitle;
    ob_start();
    ?>
    <main class="sie-landing">
        <header class="sie-site-header">
            <a class="sie-logo" href="/study-interest/"><span class="sie-logo-mark" aria-hidden="true"></span><span><?= study_interest_h($assessmentTitle) ?></span></a>
            <span class="sie-time-badge"><?= $questionCount ?> <?= study_interest_h((string)($publicCopy['question_count_suffix'] ?? 'questions')) ?> <i></i> <?= study_interest_h((string)($publicCopy['duration_prefix'] ?? 'about')) ?> <?= $estimatedMinutes ?> <?= study_interest_h((string)($publicCopy['duration_suffix'] ?? 'minutes')) ?></span>
        </header>
        <div class="sie-landing-grid">
            <section class="sie-hero" aria-labelledby="sie-landing-title">
                <p class="sie-kicker"><?= study_interest_h((string)($publicCopy['landing_kicker'] ?? 'EXPLORE YOUR INTERESTS')) ?></p>
                <h1 id="sie-landing-title"><?= study_interest_h($assessmentTitle) ?></h1>
                <p class="sie-hero-lead"><?= study_interest_h($assessmentDescription) ?></p>
                <div class="sie-hero-graphic" aria-hidden="true">
                    <span class="sie-orbit sie-orbit-one"></span><span class="sie-orbit sie-orbit-two"></span><span class="sie-orbit sie-orbit-three"></span>
                     <strong><?= $questionCount ?></strong><small><?= study_interest_h((string)($publicCopy['graphic_label'] ?? $publicCopy['question_count_suffix'] ?? 'questions')) ?></small>
                </div>
                <ul class="sie-feature-list"><li><b><?= study_interest_h((string)($publicCopy['feature_one_title'] ?? 'No timer')) ?></b><span><?= study_interest_h((string)($publicCopy['feature_one_body'] ?? 'Answer at your own pace.')) ?></span></li><li><b><?= study_interest_h((string)($publicCopy['feature_two_title'] ?? 'Saved automatically')) ?></b><span><?= study_interest_h((string)($publicCopy['feature_two_body'] ?? 'Continue in the same browser.')) ?></span></li><li><b><?= study_interest_h((string)($publicCopy['feature_three_title'] ?? 'No right answer')) ?></b><span><?= study_interest_h((string)($publicCopy['feature_three_body'] ?? 'Choose what fits you most closely.')) ?></span></li></ul>
            </section>

            <form id="sie-start-form" class="sie-start-panel" novalidate>
                <div class="sie-panel-heading"><span>01</span><div><p class="sie-kicker"><?= study_interest_h((string)($publicCopy['intake_kicker'] ?? $publicCopy['landing_kicker'] ?? 'BEFORE YOU BEGIN')) ?></p><h2><?= study_interest_h((string)($intake['heading'] ?? 'Before you begin')) ?></h2><p><?= study_interest_h((string)($intake['introduction'] ?? '')) ?></p></div></div>
                <div class="sie-fields">
                    <?php foreach ($identityFields as $key => $field): ?><label class="sie-field<?= $key === 'name' ? ' sie-field-wide' : '' ?>"><span><?= study_interest_h((string)$field['label']) ?><?= !empty($field['required']) ? ' <b>*</b>' : '' ?></span><input data-sie-intake="<?= study_interest_h($key) ?>" type="<?= study_interest_h((string)$field['type']) ?>" maxlength="<?= (int)$field['maximum'] ?>"<?= !empty($field['required']) ? ' required' : '' ?> placeholder="<?= study_interest_h((string)$field['placeholder']) ?>"><?php if ((string)$field['help'] !== ''): ?><small><?= study_interest_h((string)$field['help']) ?></small><?php endif; ?></label><?php endforeach; ?>
                </div>
                <?php if ((string)($intake['privacy_body'] ?? '') !== ''): ?><p class="sie-privacy-copy"><?= study_interest_h((string)$intake['privacy_body']) ?><?php if ((string)($intake['privacy_url'] ?? '') !== ''): ?> <a href="<?= study_interest_h((string)$intake['privacy_url']) ?>" target="_blank" rel="noopener noreferrer"><?= study_interest_h(__('Privacy policy')) ?></a><?php endif; ?></p><?php endif; ?>
                <?php if ($contactFields !== []): ?>
                <div class="sie-contact-block">
                    <div class="sie-contact-heading"><div><span><?= study_interest_h((string)($intake['contact_heading'] ?? 'Contact')) ?></span></div><p><?= study_interest_h((string)($intake['contact_introduction'] ?? '')) ?></p></div>
                    <div class="sie-fields sie-contact-fields">
                        <?php foreach ($contactFields as $key => $field): ?><label class="sie-field"><span><?= study_interest_h((string)$field['label']) ?><?= !empty($field['required']) ? ' <b>*</b>' : '' ?></span><input data-sie-intake="<?= study_interest_h($key) ?>" type="<?= study_interest_h((string)$field['type']) ?>" maxlength="<?= (int)$field['maximum'] ?>" autocomplete="<?= $key === 'email' ? 'email' : 'tel' ?>"<?= !empty($field['required']) ? ' required' : '' ?> placeholder="<?= study_interest_h((string)$field['placeholder']) ?>"><?php if ((string)$field['help'] !== ''): ?><small><?= study_interest_h((string)$field['help']) ?></small><?php endif; ?></label><?php endforeach; ?>
                    </div>
                    <label class="sie-check sie-contact-check"><input id="sie-contact-consent" type="checkbox"><span><?= study_interest_h((string)($intake['contact_consent_label'] ?? 'I agree to be contacted.')) ?></span></label>
                </div>
                <?php endif; ?>
                <label class="sie-check sie-main-consent"><input id="sie-consent" type="checkbox" required><span><?= study_interest_h((string)($intake['assessment_consent_label'] ?? 'I understand and consent to this assessment.')) ?></span></label>
                <div class="sie-start-actions"><button id="sie-start" class="sie-button" type="submit" disabled><?= study_interest_h((string)($publicCopy['start_label'] ?? 'Start')) ?> <span aria-hidden="true">&rarr;</span></button><p id="sie-message" role="status" aria-live="polite"></p></div>
                <noscript><p class="sie-form-error"><?= $genericPublished ? 'JavaScript is required to start and save this assessment.' : 'JavaScript diperlukan untuk memulai dan menyimpan jawaban eksplorasi ini.' ?></p></noscript>
            </form>
        </div>
    </main>
    <?php
    $content_html = (string)ob_get_clean();
    $page_data = ['mode' => 'landing', 'csrf' => $csrf, 'startUrl' => '/study-interest/api/start', 'copy' => $publicCopy,
        'versionId' => is_array($publishedVersion) ? (int)$publishedVersion['id'] : 0,
        'configurationHash' => is_array($publishedVersion) ? (string)$publishedVersion['configuration_hash'] : ''];
}

require __DIR__ . '/layout.php';
