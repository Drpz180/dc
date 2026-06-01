<?php
require_once __DIR__ . '/../../app/models/Campaign.php';
require_once __DIR__ . '/../../app/config.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$campaign = $id ? Campaign::findById($id) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $_POST['title']), '-'));

    $coverImage = $campaign['cover_image'] ?? null;

    // aceita upload de cover_image (imagem) existente no arquivo...
    if (!empty($_FILES['cover_image']['name'])) {
        $name = time() . '_' . basename($_FILES['cover_image']['name']);
        $uploadDir = __DIR__ . '/../uploads/campaigns';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        $dest = $uploadDir . '/' . $name;
        if (move_uploaded_file($_FILES['cover_image']['tmp_name'], $dest)) {
            $coverImage = 'uploads/campaigns/' . $name;
        }
    }

    // Novo: tratamento de upload de vídeo (se enviado, sobrescreve $coverImage com o file path do vídeo)
    if (!empty($_FILES['cover_video']['name'])) {
        $name = time() . '_video_' . basename($_FILES['cover_video']['name']);
        $uploadDir = __DIR__ . '/../uploads/campaigns';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        $dest = $uploadDir . '/' . $name;
        if (move_uploaded_file($_FILES['cover_video']['tmp_name'], $dest)) {
            // armazenamos no mesmo campo cover_image para não exigir alteração no DB
            $coverImage = 'uploads/campaigns/' . $name;
        }
    }

    $data = [
        'id'                    => $id ?: null,
        'title'                 => $_POST['title'],
        'subtitle'              => $_POST['subtitle'] ?? null,
        'slug'                  => $slug,
        'category'              => $_POST['category'] ?? null,
        'city'                  => $_POST['city'] ?? null,
        'state'                 => $_POST['state'] ?? null,
        'min_amount'            => $_POST['min_amount'] ?? 25,
        'goal_amount'           => $_POST['goal_amount'] ?? 0,
        'raised_amount'         => $_POST['raised_amount'] ?? 0,
        'pix_key'               => $_POST['pix_key'] ?? null,
        'pix_description'       => $_POST['pix_description'] ?? null,
        'facebook_pixel_id'     => $_POST['facebook_pixel_id'] ?? null,
        'facebook_access_token' => $_POST['facebook_access_token'] ?? null,
        'tiktok_pixel_id'       => $_POST['tiktok_pixel_id'] ?? null,
        // NOVO: TikTok Access Token
        'tiktok_access_token'   => $_POST['tiktok_access_token'] ?? null,
        'utmify_api_token'      => $_POST['utmify_api_token'] ?? null,
        'cover_image'           => $coverImage,
        'description'           => $_POST['description'] ?? null,
        'hearts_received'       => isset($_POST['hearts_received']) ? (int)$_POST['hearts_received'] : 0,
        'supporters'            => isset($_POST['supporters']) ? (int)$_POST['supporters'] : 0,
        'is_active'             => isset($_POST['is_active']) ? 1 : 0,
    ];

    $savedId = Campaign::save($data);
    header("Location: index.php");
    exit;
}

?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <title><?= $campaign ? 'Editar' : 'Nova' ?> Campanha</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="../css/style.css">
  <style>
    :root{
      --bg:#f3f6f8;
      --card:#ffffff;
      --accent:#00c853;
      --muted:#6b7280;
      --input-bg:#fafafa;
      --radius:12px;
      --shadow: 0 8px 28px rgba(8,15,26,0.08);
    }
    *{box-sizing:border-box}
    body{margin:0;font-family:Inter,system-ui,Roboto,Arial;background:var(--bg);color:#17202a;min-height:100vh;padding:28px;}
    .container{max-width:980px;margin:0 auto}
    .card{
      background:var(--card);
      border-radius:var(--radius);
      padding:28px;
      box-shadow:var(--shadow);
      overflow:hidden;
    }
    .card-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:18px}
    .card-head h1{margin:0;color:var(--accent);font-size:1.6rem}
    .card-sub{color:var(--muted);font-size:0.95rem}

    /* grid */
    .form-grid{display:grid;grid-template-columns:1fr 360px;gap:20px}
    @media (max-width:900px){ .form-grid{grid-template-columns:1fr} }

    .panel{background:transparent;border-radius:10px}
    .field{margin-bottom:14px}
    label{display:block;font-weight:600;margin-bottom:6px;color:#22303a;font-size:0.95rem}
    input[type=text],input[type=number],input[type=file],textarea,select{
      width:100%;padding:10px 12px;border-radius:10px;border:1px solid #e6e9ee;background:var(--input-bg);font-size:0.96rem;color:#0b1720;
    }
    textarea{min-height:120px;resize:vertical}
    .row{display:flex;gap:12px}
    .row .col{flex:1}

    .actions{display:flex;gap:12px;justify-content:flex-end;margin-top:18px}
    .btn{padding:10px 16px;border-radius:10px;border:none;cursor:pointer;font-weight:700}
    .btn-save{background:var(--accent);color:#fff}
    .btn-cancel{background:#eef2f4;color:#334;box-shadow:inset 0 -1px 0 rgba(0,0,0,0.03)}

    /* preview */
    .preview-card{background:linear-gradient(180deg,#fbfffb,#f7fff7);border-radius:10px;padding:16px;border:1px solid #ecf7ee;text-align:center}
    .preview-media{width:100%;height:200px;border-radius:8px;object-fit:cover;background:#f1f1f1;display:flex;align-items:center;justify-content:center;color:var(--muted)}
    .meta{margin-top:12px;text-align:left}
    .meta .meta-row{display:flex;justify-content:space-between;align-items:center;padding:6px 0;border-bottom:1px dashed rgba(0,0,0,0.04)}
    .small{font-size:0.88rem;color:var(--muted)}

    /* file input nicer */
    .file-input{
      display:flex;align-items:center;gap:10px;padding:10px;border-radius:10px;background:#fff;border:1px dashed #e6ece0;
    }
    .file-input input[type=file]{border:0;padding:0;background:transparent}
    .file-info{font-size:0.9rem;color:var(--muted)}

    /* helper */
    .hint{font-size:0.88rem;color:var(--muted);margin-top:6px}
  </style>
</head>
<body>
  <div class="container">
    <div class="card" role="main">
      <div class="card-head">
        <div>
          <h1><?= $campaign ? 'Editar' : 'Nova' ?> Campanha</h1>
          <div class="card-sub">Preencha as informações abaixo para <?= $campaign ? 'editar' : 'criar' ?> a campanha.</div>
        </div>
        <div style="display:flex;gap:8px;align-items:center">
          <a class="btn btn-cancel" href="index.php">Voltar</a>
        </div>
      </div>

      <form method="post" enctype="multipart/form-data" id="campaignForm">
        <div class="form-grid">
          <div class="panel">
            <div class="field">
              <label for="title">Título</label>
              <input id="title" name="title" type="text" value="<?= htmlspecialchars($campaign['title'] ?? '') ?>" required>
            </div>

            <div class="row">
              <div class="col field">
                <label for="subtitle">Subtítulo</label>
                <input id="subtitle" name="subtitle" type="text" value="<?= htmlspecialchars($campaign['subtitle'] ?? '') ?>">
              </div>
              <div class="col field">
                <label for="category">Categoria</label>
                <input id="category" name="category" type="text" value="<?= htmlspecialchars($campaign['category'] ?? 'Saúde / Tratamentos') ?>">
              </div>
            </div>

            <div class="row">
              <div class="col field">
                <label for="city">Cidade</label>
                <input id="city" name="city" type="text" value="<?= htmlspecialchars($campaign['city'] ?? '') ?>">
              </div>
              <div class="col field">
                <label for="state">Estado (UF)</label>
                <input id="state" name="state" type="text" maxlength="2" value="<?= htmlspecialchars($campaign['state'] ?? '') ?>">
              </div>
            </div>

            <div class="row">
              <div class="col field">
                <label for="min_amount">Valor mínimo da doação (R$)</label>
                <input id="min_amount" name="min_amount" type="number" step="0.01" min="1" value="<?= htmlspecialchars($campaign['min_amount'] ?? '25') ?>">
              </div>
            </div>

            <div class="row">
              <div class="col field">
                <label for="goal_amount">Meta (R$)</label>
                <input id="goal_amount" name="goal_amount" type="number" step="0.01" value="<?= htmlspecialchars($campaign['goal_amount'] ?? '0') ?>">
              </div>
              <div class="col field">
                <label for="raised_amount">Arrecadado (R$)</label>
                <input id="raised_amount" name="raised_amount" type="number" step="0.01" value="<?= htmlspecialchars($campaign['raised_amount'] ?? '0') ?>">
              </div>
            </div>

            <div class="row">
              <div class="col field">
                <label for="hearts_received">Corações recebidos</label>
                <input id="hearts_received" name="hearts_received" type="number" min="0" value="<?= htmlspecialchars($campaign['hearts_received'] ?? 0) ?>">
              </div>
              <div class="col field">
                <label for="supporters">Apoiadores</label>
                <input id="supporters" name="supporters" type="number" min="0" value="<?= htmlspecialchars($campaign['supporters'] ?? 0) ?>">
              </div>
            </div>

            <div class="field">
              <label for="pix_key">Chave PIX</label>
              <input id="pix_key" name="pix_key" type="text" value="<?= htmlspecialchars($campaign['pix_key'] ?? '') ?>">
              <div class="hint">Texto e chave podem aparecer na página da campanha.</div>
            </div>

            <div class="field">
              <label for="pix_description">Texto sobre o PIX</label>
              <textarea id="pix_description" name="pix_description"><?= htmlspecialchars($campaign['pix_description'] ?? '') ?></textarea>
            </div>

            <div class="field">
              <label for="facebook_pixel_id">Facebook Pixel ID</label>
              <input id="facebook_pixel_id" name="facebook_pixel_id" type="text" value="<?= htmlspecialchars($campaign['facebook_pixel_id'] ?? '') ?>">
            </div>

            <div class="field">
              <label for="facebook_access_token">Facebook Access Token</label>
              <input id="facebook_access_token" name="facebook_access_token" type="text" value="<?= htmlspecialchars($campaign['facebook_access_token'] ?? '') ?>">
            </div>

            <!-- NOVO: TikTok Pixel ID -->
            <div class="field">
              <label for="tiktok_pixel_id">TikTok Pixel ID</label>
              <input id="tiktok_pixel_id" name="tiktok_pixel_id" type="text" value="<?= htmlspecialchars($campaign['tiktok_pixel_id'] ?? '') ?>">
              <div class="hint">ID do pixel configurado no Events Manager.</div>
            </div>

            <!-- NOVO: TikTok Access Token (para Events API) -->
            <div class="field">
              <label for="tiktok_access_token">TikTok Access Token</label>
              <input id="tiktok_access_token" name="tiktok_access_token" type="text" value="<?= htmlspecialchars($campaign['tiktok_access_token'] ?? '') ?>">
              <div class="hint">Token de acesso usado na Web Events API (header <code>Access-Token</code>).</div>
            </div>

            <div class="field">
              <label for="utmify_api_token">Utmify - API Token</label>
              <input id="utmify_api_token" name="utmify_api_token" type="text" value="<?= htmlspecialchars($campaign['utmify_api_token'] ?? '') ?>">
            </div>

            <div class="field">
              <label for="description">Descrição completa</label>
              <textarea id="description" name="description"><?= htmlspecialchars($campaign['description'] ?? '') ?></textarea>
            </div>

            <div class="field">
              <label class="small"><input type="checkbox" id="is_active" name="is_active" <?= !empty($campaign['is_active']) ? 'checked' : '' ?>> Campanha ativa</label>
            </div>

            <div class="actions">
              <button type="submit" class="btn btn-save">Salvar</button>
              <a class="btn btn-cancel" href="index.php">Cancelar</a>
            </div>
          </div>

          <aside class="panel">
            <div class="preview-card">
              <div id="previewMedia" class="preview-media">
                <?php
                  // helper PHP para detectar vídeo
                  function isVideoPath($p) {
                      $ext = strtolower(pathinfo($p, PATHINFO_EXTENSION));
                      return in_array($ext, ['mp4','webm','ogg','mov','mkv','avi']);
                  }
                ?>
                <?php if (!empty($campaign['cover_image']) && isVideoPath($campaign['cover_image'])): ?>
                  <video id="existingVideo" class="preview-media" src="../<?= htmlspecialchars($campaign['cover_image']) ?>" controls style="height:200px;border-radius:8px"></video>
                <?php elseif (!empty($campaign['cover_image'])): ?>
                  <img id="existingImage" class="preview-media" src="../<?= htmlspecialchars($campaign['cover_image']) ?>" alt="preview" style="height:200px;border-radius:8px;object-fit:cover">
                <?php else: ?>
                  <div id="noPreview" class="preview-media">Sem imagem / vídeo</div>
                <?php endif; ?>
              </div>

              <div class="meta">
                <div class="meta-row">
                  <div class="small">Enviar imagem de capa</div>
                </div>

                <div style="margin-top:8px" class="file-input">
                  <span class="file-info">PNG/JPG até 5MB</span>
                  <input type="file" name="cover_image" id="cover_image" accept="image/*">
                </div>

                <div class="meta-row" style="margin-top:12px">
                  <div class="small">Ou enviar vídeo (substitui imagem)</div>
                </div>

                <div style="margin-top:8px" class="file-input">
                  <span class="file-info">MP4/WebM até 20MB</span>
                  <input type="file" name="cover_video" id="cover_video" accept="video/*">
                </div>

                <div class="meta-row" style="margin-top:12px">
                  <div class="small">Slug:</div>
                  <div class="small"><?= htmlspecialchars($campaign['slug'] ?? '(será gerado)') ?></div>
                </div>

                <div class="meta-row">
                  <div class="small">Criado em:</div>
                  <div class="small"><?= htmlspecialchars($campaign['created_at'] ?? date('Y-m-d H:i:s')) ?></div>
                </div>
              </div>
            </div>
          </aside>
        </div>
      </form>
    </div>
  </div>

  <script>
    (function(){
      const imgInput = document.getElementById('cover_image');
      const vidInput = document.getElementById('cover_video');
      const preview = document.getElementById('previewMedia');
      const noPreview = document.getElementById('noPreview');

      function clearPreview(){
        preview.innerHTML = '';
      }

      function showImage(file){
        const url = URL.createObjectURL(file);
        clearPreview();
        const img = document.createElement('img');
        img.src = url;
        img.style.width = '100%';
        img.style.height = '200px';
        img.style.objectFit = 'cover';
        img.style.borderRadius = '8px';
        preview.appendChild(img);
      }

      function showVideo(file){
        const url = URL.createObjectURL(file);
        clearPreview();
        const v = document.createElement('video');
        v.src = url;
        v.controls = true;
        v.style.width = '100%';
        v.style.height = '200px';
        v.style.objectFit = 'cover';
        v.style.borderRadius = '8px';
        v.playsInline = true;
        preview.appendChild(v);
      }

      imgInput && imgInput.addEventListener('change', function(e){
        if (!e.target.files || !e.target.files[0]) return;
        // if a video is selected, prioritize video only when user selects; otherwise show image
        showImage(e.target.files[0]);
      });

      vidInput && vidInput.addEventListener('change', function(e){
        if (!e.target.files || !e.target.files[0]) return;
        showVideo(e.target.files[0]);
      });
    })();
  </script>
</body>
</html>
