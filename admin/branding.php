<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';
adiwira_require_permission($pdo, 'plugin.study-interest.presentation.view', false);
$branding = study_interest_public_branding($pdo);
$canManage = study_interest_admin_can('plugin.study-interest.presentation.manage');
?>
<div class="sie-admin sie-workspace-page" data-sie-branding data-sie-media-picker-url="<?= study_interest_h(rtrim((string)ADMIN_BASE_PATH, '/') . '/admin/modal_img/index.php?embedded=1&visibility=public') ?>">
  <?php study_interest_admin_nav('branding'); ?>
  <header class="sie-admin-head"><div><span class="sie-admin-eyebrow"><?= study_interest_h(__('Participant experience')) ?></span><h1 class="page-heading"><?= study_interest_h(__('Public branding')) ?></h1><p><?= study_interest_h(__('Set the logo and brand title shown in the public assessment header without changing assessment versions, scoring, or results.')) ?></p></div><div class="sie-admin-actions"><a class="adam-button secondary" href="/study-interest/" target="_blank" rel="noopener"><?= study_interest_h(__('Preview public assessment')) ?> <span aria-hidden="true">&nearr;</span></a></div></header>
  <section class="sie-guidance-card"><strong><?= study_interest_h(__('Global presentation')) ?></strong><p><?= study_interest_h(__('These values apply immediately to landing, in-progress, and completed-session headers. Leave either field empty to use the assessment title and built-in mark.')) ?></p></section>
  <form class="sie-presentation-form" method="post" action="<?= study_interest_h(study_interest_admin_action_url('branding/save')) ?>">
    <input type="hidden" name="csrf_token" value="<?= study_interest_h(csrf_token()) ?>"><input type="hidden" name="expected_hash" value="<?= study_interest_h(study_interest_public_branding_hash($branding)) ?>">
    <section class="sie-editor-panel sie-branding-panel"><div class="sie-panel-title"><div><span class="sie-admin-eyebrow"><?= study_interest_h(__('Header identity')) ?></span><h2><?= study_interest_h(__('Logo and brand title')) ?></h2></div></div><div class="sie-branding-layout"><div class="sie-branding-preview" data-sie-branding-preview data-sie-branding-fallback-title="<?= study_interest_h(__('Assessment title')) ?>"><img<?php if ((string)$branding['logo_image_url'] !== ''): ?> src="<?= study_interest_h((string)$branding['logo_image_url']) ?>"<?php endif; ?> alt="" <?= (string)$branding['logo_image_url'] === '' ? 'hidden' : '' ?>><span class="sie-branding-default-mark" <?= (string)$branding['logo_image_url'] !== '' ? 'hidden' : '' ?> aria-hidden="true"></span><strong><?= study_interest_h((string)$branding['brand_title'] !== '' ? (string)$branding['brand_title'] : __('Assessment title')) ?></strong></div><div class="sie-branding-fields"><label><span><?= study_interest_h(__('Brand title')) ?></span><input class="adam-input" name="brand_title" maxlength="120" value="<?= study_interest_h((string)$branding['brand_title']) ?>" placeholder="<?= study_interest_h(__('Use the active assessment title')) ?>" data-sie-branding-title <?= $canManage ? '' : 'readonly' ?>></label><label><span><?= study_interest_h(__('Logo image URL')) ?></span><input class="adam-input" name="logo_image_url" maxlength="2048" value="<?= study_interest_h((string)$branding['logo_image_url']) ?>" placeholder="/static/img/brand-logo.svg" data-sie-branding-url <?= $canManage ? '' : 'readonly' ?>></label><?php if ($canManage): ?><div class="sie-branding-actions"><button class="adam-button secondary" type="button" data-sie-branding-choose><?= study_interest_h(__('Choose from Media Library')) ?></button><button class="adam-button secondary" type="button" data-sie-branding-clear><?= study_interest_h(__('Use default mark')) ?></button></div><?php endif; ?><p class="sie-panel-note"><?= study_interest_h(__('Use a public root-relative URL or HTTPS image. The image is constrained in the header; transparent horizontal logos work best.')) ?></p></div></div></section>
    <?php if ($canManage): ?><div class="sie-sticky-save"><div><strong><?= study_interest_h(__('Global public branding')) ?></strong><span><?= study_interest_h(__('Changes are immediate and recorded in Activity log.')) ?></span></div><button class="adam-button" type="submit"><?= study_interest_h(__('Save branding')) ?></button></div><?php else: ?><div class="sie-callout"><strong><?= study_interest_h(__('Read-only branding')) ?></strong><p><?= study_interest_h(__('You can review public branding but do not have permission to change it.')) ?></p></div><?php endif; ?>
  </form>
</div>
<script src="/static/js/add/modal-helpers.js"></script>
<script src="/static/js/add/media-selector.js"></script>
<script>
(function () {
  const branding = document.querySelector('[data-sie-branding]');
  if (!branding || branding.dataset.sieBrandingReady === '1') return;
  branding.dataset.sieBrandingReady = '1';

  const input = branding.querySelector('[data-sie-branding-url]');
  const titleInput = branding.querySelector('[data-sie-branding-title]');
  const preview = branding.querySelector('[data-sie-branding-preview]');
  const image = preview?.querySelector('img');
  const mark = preview?.querySelector('.sie-branding-default-mark');
  const title = preview?.querySelector('strong');
  const mediaPickerUrl = branding.dataset.sieMediaPickerUrl || '';
  const notifyError = (message) => {
    if (window.NewNotifToast && typeof window.NewNotifToast.show === 'function') {
      window.NewNotifToast.show({type: 'error', title: <?= json_encode(__('Media Library'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>, message});
    }
  };
  const updatePreview = () => {
    const url = input?.value.trim() || '';
    if (image && url) { image.src = url; image.hidden = false; }
    if (image && !url) { image.removeAttribute('src'); image.hidden = true; }
    if (mark) mark.hidden = url !== '';
    if (title) title.textContent = titleInput?.value.trim() || preview?.dataset.sieBrandingFallbackTitle || '';
  };

  branding.querySelector('[data-sie-branding-clear]')?.addEventListener('click', () => {
    if (!input) return;
    input.value = '';
    updatePreview();
    input.focus();
  });
  branding.querySelector('[data-sie-branding-choose]')?.addEventListener('click', () => {
    if (!input || !mediaPickerUrl || typeof window.openMediaSelector !== 'function') {
      notifyError(<?= json_encode(__('Media selector not available yet.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
      return;
    }
    window.openMediaSelector({url: mediaPickerUrl})
      .then((detail) => {
        const media = typeof window.normalizeMedia === 'function' ? window.normalizeMedia(detail) : detail;
        if (!media?.url) return;
        input.value = media.url;
        updatePreview();
      })
      .catch((error) => {
        console.error('Study Interest media selector error', error);
        notifyError(<?= json_encode(__('Failed to select media.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
      });
  });
  input?.addEventListener('input', updatePreview);
  titleInput?.addEventListener('input', updatePreview);
  image?.addEventListener('error', () => { image.hidden = true; if (mark) mark.hidden = false; });
  updatePreview();
})();
</script>
