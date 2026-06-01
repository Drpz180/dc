<?php
// Página inicial simples
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8" />
  <title>Minha Vaquinha - Página Inicial</title>
</head>
<body>
  <h1>Bem-vindo ao Minha Vaquinha!</h1>
  <p><a href="admin/index.php">Ir para o painel administrativo</a></p>
  <p>Para acessar uma campanha, use: <code>campaign.php?slug=seu-slug</code></p>
</body>
<footer style="background:#222; color:#eee; margin-top:40px; padding:0;">
  <div style="max-width:1400px; margin:0 auto; padding:38px 0 18px 0; display:flex; gap:0; flex-wrap:wrap; justify-content:space-between;">
    <div style="flex:1; min-width:220px; margin-left:38px;">
      <img src="https://vakinha.com.br/assets/logo-vakinha.svg" alt="vakinha" style="height:32px;margin-bottom:10px;">
      <h4 style="color:#00c853; margin:10px 0 8px 0;">Links rápidos</h4>
      <ul style="list-style:none; padding:0; margin:0;">
        <li><a href="#" style="color:#eee;">Quem somos</a></li>
        <li><a href="#" style="color:#eee;">Vaquinhas</a></li>
        <li><a href="#" style="color:#eee;">Criar vaquinha</a></li>
        <li><a href="#" style="color:#eee;">Login</a></li>
        <li><a href="#" style="color:#eee;">Política de privacidade</a></li>
      </ul>
    </div>
    <div style="flex:1; min-width:220px;">
      <h4 style="color:#00c853; margin:10px 0 8px 0;">Dúvidas frequentes</h4>
      <ul style="list-style:none; padding:0; margin:0;">
        <li><a href="#" style="color:#eee;">Taxas e prazos</a></li>
        <li><a href="#" style="color:#eee;">Blog do Vakinha</a></li>
        <li><a href="#" style="color:#eee;">Segurança e transparência</a></li>
      </ul>
    </div>
    <div style="flex:1; min-width:220px;">
      <h4 style="color:#00c853; margin:10px 0 8px 0;">Fale conosco</h4>
      <ul style="list-style:none; padding:0; margin:0;">
        <li><a href="#" style="color:#eee;">Clique aqui para falar conosco</a></li>
        <li style="color:#eee;">De Segunda à Sexta<br>Das 9:30 às 17:00</li>
        <li style="margin-top:10px;">
          <img src="https://vakinha.com.br/assets/selo-seguranca.svg" alt="Selo de segurança" style="height:38px;">
        </li>
      </ul>
    </div>
    <div style="flex:1; min-width:220px;">
      <h4 style="color:#00c853; margin:10px 0 8px 0;">Baixe nosso App</h4>
      <ul style="list-style:none; padding:0; margin:0;">
        <li><a href="#"><img src="https://vakinha.com.br/assets/google-play-badge.svg" alt="Google Play" style="height:32px;"></a></li>
        <li><a href="#"><img src="https://vakinha.com.br/assets/app-store-badge.svg" alt="App Store" style="height:32px;"></a></li>
      </ul>
    </div>
  </div>
  <div style="background:#444; color:#eee; text-align:center; padding:8px 0; font-size:0.98em;">
    © <?= date('Y') ?> - Todos direitos reservados
  </div>
</footer>
</html>
