<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Correo verificado — Prixma</title>
  <style>
    @font-face {
      font-family: 'Poppins';
      src: url('{{ asset('prixma_resources/fonts/Poppins-Regular.ttf') }}') format('truetype');
      font-weight: 400;
    }
    @font-face {
      font-family: 'Poppins';
      src: url('{{ asset('prixma_resources/fonts/Poppins-Medium.ttf') }}') format('truetype');
      font-weight: 500;
    }
    @font-face {
      font-family: 'Poppins';
      src: url('{{ asset('prixma_resources/fonts/Poppins-SemiBold.ttf') }}') format('truetype');
      font-weight: 600;
    }

    * { margin: 0; padding: 0; box-sizing: border-box; }

    body {
      background-color: #161622;
      font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
      display: flex;
      align-items: center;
      justify-content: center;
      min-height: 100vh;
      padding: 24px;
    }

    .card {
      max-width: 520px;
      width: 100%;
      background: #12121e;
      border-radius: 16px;
      border: 1px solid #2a2a3a;
      overflow: hidden;
    }

    .card-header {
      background-color: #0d0d14;
      padding: 28px 32px;
      text-align: center;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 10px;
    }

    .logo-text {
      font-size: 20px;
      font-weight: 500;
      color: #ffffff;
      letter-spacing: -0.5px;
    }

    .card-body {
      padding: 40px 40px 36px;
      text-align: center;
    }

    .success-icon {
      width: 56px;
      height: 56px;
      background: rgba(155, 93, 255, 0.12);
      border-radius: 16px;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 24px;
    }

    h1 {
      font-size: 22px;
      font-weight: 500;
      color: #ffffff;
      margin-bottom: 12px;
      line-height: 1.3;
    }

    .subtitle {
      font-size: 15px;
      color: #8e8ea8;
      line-height: 1.65;
    }

    .card-footer {
      border-top: 1px solid #2a2a3a;
      padding: 20px 40px;
      text-align: center;
    }

    .card-footer p {
      font-size: 12px;
      color: #555568;
      line-height: 1.6;
    }

    .card-footer a {
      color: #555568;
      text-decoration: none;
    }
  </style>
</head>
<body>
  <div class="card">

    <div class="card-header">
      <img src="{{ asset('prixma_resources/png/appiconColor.png') }}"
           width="32" height="32" alt="Prixma"
           style="border-radius: 9px; display: block; flex-shrink: 0;">
      <span class="logo-text">prixma</span>
    </div>

    <div class="card-body">
      <div class="success-icon">
        <svg width="28" height="28" viewBox="0 0 24 24" fill="none">
          <path d="M5 13l4 4L19 7" stroke="#9b5dff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
      </div>

      <h1>Tu correo ha sido verificado.</h1>
      <p class="subtitle">Ya puedes volver a la app y seguir siendo tú.</p>
    </div>

    <div class="card-footer">
      <p>
        Conecta siendo tú &mdash;
        <a href="#">Términos de uso</a>
        &nbsp;·&nbsp;
        <a href="#">Privacidad</a>
      </p>
    </div>

  </div>
</body>
</html>
