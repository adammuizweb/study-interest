<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';
$page_title = 'Study Interest Explorer';
$csrf = function_exists('stateless_csrf_token') ? stateless_csrf_token() : '';
$content_html = '<main class="sie-shell">'
    . '<p class="sie-eyebrow">EXPLORATION TOOL</p>'
    . '<h1>Study Interest Explorer</h1>'
    . '<p class="sie-lead">Explore your study interests and receive a starting point for further discussion. This is not a diagnosis, aptitude test, or academic ability assessment.</p>'
    . '<div class="sie-card"><h2>Before you begin</h2><p>The assessment is flexible, has no proctoring, and saves your progress in this browser.</p>'
    . '<label class="sie-consent"><input id="sie-consent" type="checkbox"> I understand the purpose and consent to begin.</label>'
    . '<button id="sie-start" type="button" disabled>Start exploration</button><p id="sie-message" role="status"></p></div>'
    . '</main><script>window.studyInterestConfig=' . json_encode([
        'csrf' => $csrf,
        'startUrl' => '/study-interest/api/start',
    ], JSON_UNESCAPED_SLASHES) . ';document.querySelector("#sie-consent").addEventListener("change",function(){document.querySelector("#sie-start").disabled=!this.checked});document.querySelector("#sie-start").addEventListener("click",async function(){this.disabled=true;const r=await fetch(window.studyInterestConfig.startUrl,{method:"POST",headers:{"Content-Type":"application/json","X-CSRF-Token":window.studyInterestConfig.csrf},body:JSON.stringify({consent:true})});const d=await r.json();if(d.ok){location.href="/study-interest/?session="+encodeURIComponent(d.session.public_id)}else{document.querySelector("#sie-message").textContent=d.error||"Unable to start";this.disabled=false}});</script>';
require __DIR__ . '/layout.php';
