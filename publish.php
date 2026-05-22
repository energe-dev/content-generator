<?php
/**
 * Energe Auto-Publishing API Endpoint
 * 
 * Este script recibe los datos del artículo en formato JSON desde el panel de redacción,
 * genera una página estática HTML utilizando la plantilla exacta del sitio corporativo,
 * y actualiza de forma automática el listado de notas en blog.html.
 */

// Permite peticiones CORS de desarrollo de manera robusta y compatible con preflight OPTIONS
if (isset($_SERVER['HTTP_ORIGIN'])) {
    header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}");
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Max-Age: 86400');
} else {
    header("Access-Control-Allow-Origin: *");
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'])) {
        header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    }
    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'])) {
        header("Access-Control-Allow-Headers: {$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']}");
    }
    exit(0);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: application/json');
    echo json_encode(["ok" => false, "error" => "Método no permitido"]);
    exit;
}

// 1. Configuración de Seguridad
// IMPORTANTE: Definí tu token secreto para evitar que terceros publiquen contenido
define('SECRET_TOKEN', '33711409179');

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

if (!$data) {
    header('Content-Type: application/json');
    echo json_encode(["ok" => false, "error" => "Datos JSON no válidos"]);
    exit;
}

// Verificar token
$clientSecret = $data['secret'] ?? '';
if (empty($clientSecret) || $clientSecret !== SECRET_TOKEN) {
    header('Content-Type: application/json');
    echo json_encode(["ok" => false, "error" => "Token secreto no válido o ausente"]);
    exit;
}

// Si es solo una prueba de conexión, responder OK inmediatamente
if (isset($data['test']) && $data['test'] === true) {
    header('Content-Type: application/json');
    echo json_encode(["ok" => true, "message" => "Endpoint activo y verificado"]);
    exit;
}

// 2. Procesamiento de Campos
$title = $data['title'] ?? '';
$excerpt = $data['excerpt'] ?? '';
$body = $data['body'] ?? '';
$tags = $data['tags'] ?? [];
$slug = $data['slug'] ?? '';
$category = $data['category'] ?? 'legal'; // legal, roi, casos, prensa, industria
$author = $data['author'] ?? 'Equipo Energe';
$metaDesc = $data['metaDesc'] ?? '';
$image = $data['image'] ?? null;
$readTime = $data['readTime'] ?? '5 min';
$ctaType = $data['cta'] ?? 'cotizacion'; // cotizacion, contacto, calculadora, whatsapp

if (empty($title) || empty($body) || empty($slug)) {
    header('Content-Type: application/json');
    echo json_encode(["ok" => false, "error" => "Faltan campos requeridos (título, cuerpo o slug)"]);
    exit;
}

// Limpiar el slug
$slug = preg_replace('/[^a-zA-Z0-9\-]/', '', strtolower($slug));

// Formatear Fecha en Español para la cabecera
$months = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
$currentDate = date('d') . ' de ' . $months[date('n') - 1] . ' de ' . date('Y');

// 3. Procesador Avanzado de Markdown a HTML Semántico
function markdownToHtml($markdown) {
    $lines = explode("\n", $markdown);
    $html = '';
    $inList = false;
    
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) {
            if ($inList) {
                $html .= "        </ul>\n";
                $inList = false;
            }
            continue;
        }
        
        // Headers
        if (strpos($line, '###') === 0) {
            if ($inList) { $html .= "        </ul>\n"; $inList = false; }
            $html .= "        <h3>" . htmlspecialchars(trim(substr($line, 3))) . "</h3>\n";
        } elseif (strpos($line, '##') === 0) {
            if ($inList) { $html .= "        </ul>\n"; $inList = false; }
            $html .= "        <h2>" . htmlspecialchars(trim(substr($line, 2))) . "</h2>\n";
        } elseif (strpos($line, '#') === 0) {
            if ($inList) { $html .= "        </ul>\n"; $inList = false; }
            $html .= "        <h1>" . htmlspecialchars(trim(substr($line, 1))) . "</h1>\n";
        }
        // Lists
        elseif (strpos($line, '-') === 0 || strpos($line, '*') === 0) {
            if (!$inList) {
                $html .= "        <ul>\n";
                $inList = true;
            }
            // Parse bold inside list
            $text = htmlspecialchars(trim(substr($line, 1)));
            $text = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $text);
            $html .= "          <li>" . $text . "</li>\n";
        }
        // Normal paragraphs
        else {
            if ($inList) { $html .= "        </ul>\n"; $inList = false; }
            // Parse bold inside paragraph
            $text = htmlspecialchars($line);
            $text = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $text);
            $html .= "        <p>" . $text . "</p>\n";
        }
    }
    
    if ($inList) {
        $html .= "        </ul>\n";
    }
    
    return $html;
}

$bodyHtml = markdownToHtml($body);

// 4. Mapeo de Categorías
$catLabel = 'Marco Legal';
$catColor = '#2c3e50';
$catClass = 'cat-legal';
$catStyle = '';

if ($category === 'roi') {
    $catLabel = 'Finanzas & ROI';
    $catColor = '#27ae60';
    $catClass = 'cat-roi';
} elseif ($category === 'casos') {
    $catLabel = 'Casos de Éxito';
    $catColor = '#2980b9';
    $catClass = 'cat-casos';
} elseif ($category === 'prensa') {
    $catLabel = 'Prensa';
    $catColor = '#d4721a';
    $catClass = 'cat-prensa';
    $catStyle = ' style="background:#d4721a"';
} elseif ($category === 'industria') {
    $catLabel = 'Industria & Off-Grid';
    $catColor = '#5c3d1e';
    $catClass = 'cat-industria';
    $catStyle = ' style="background:#5c3d1e"';
}

// 5. Configuración de CTAs Premium
$sidebarCtaTitle = '¿Tu empresa es elegible para el RIMI?';
$sidebarCtaSub = 'Realizamos el análisis técnico-contable para que aproveches al máximo los beneficios del Decreto 242/2026.';
$fullCtaTag = 'Diagnóstico RIMI 2026';
$fullCtaTitle = '¿Tu PyME es elegible para el <em>Régimen de Incentivos?</em>';
$fullCtaDesc = 'Analizamos la viabilidad técnica y contable de tu proyecto fotovoltaico para que obtengas el máximo ahorro impositivo posible.';
$ctaButtonText = 'Analizar mi proyecto →';

if ($ctaType === 'cotizacion') {
    $sidebarCtaTitle = '¿Querés ahorrar en tu factura eléctrica?';
    $sidebarCtaSub = 'Calculamos el retorno de tu inversión en paneles solares para tu hogar o empresa sin costo.';
    $fullCtaTag = 'Ahorro Solar';
    $fullCtaTitle = '¿Cuánto podés ahorrar con <em>Energía Solar?</em>';
    $fullCtaDesc = 'Analizamos tu consumo actual y diseñamos un sistema a la medida de tu presupuesto para eliminar tus costos fijos.';
    $ctaButtonText = 'Cotizar mi proyecto →';
} elseif ($ctaType === 'contacto') {
    $sidebarCtaTitle = '¿Tenés dudas sobre energía solar?';
    $sidebarCtaSub = 'Ponete en contacto directo con nuestros ingenieros y asesores especializados.';
    $fullCtaTag = 'Asesoría Directa';
    $fullCtaTitle = 'Hablá hoy con un <em>Asesor de Energe</em>';
    $fullCtaDesc = 'Estamos listos para asesorarte en la viabilidad técnica y financiera de tu proyecto de energía renovable.';
    $ctaButtonText = 'Contactar un asesor →';
} elseif ($ctaType === 'calculadora') {
    $sidebarCtaTitle = 'Calculá tu inversión solar';
    $sidebarCtaSub = 'Usá nuestro cotizador digital para obtener una estimación precisa en segundos.';
    $fullCtaTag = 'Cotizador Online';
    $fullCtaTitle = 'Obtené tu <em>Presupuesto Personalizado</em> al instante';
    $fullCtaDesc = 'Ingresá tus datos de consumo y conocé la cantidad de paneles que necesitás y el costo estimado.';
    $ctaButtonText = 'Usar simulador →';
} elseif ($ctaType === 'whatsapp') {
    $sidebarCtaTitle = '¿Querés una respuesta inmediata?';
    $sidebarCtaSub = 'Escribinos por WhatsApp y un asesor te responderá en minutos de forma personalizada.';
    $fullCtaTag = 'Contacto Rápido';
    $fullCtaTitle = 'Chateá ahora con nosotros por <em>WhatsApp</em>';
    $fullCtaDesc = 'Respondemos todas tus dudas técnicas y comerciales al instante. Hacé clic para iniciar la conversación.';
    $ctaButtonText = 'Iniciar chat ahora →';
}

// 6. Generación del Archivo HTML Final
$heroImage = $image ? $image : 'images/hero-drone.jpg';
$canonicalUrl = "https://energe.com.ar/blog-" . $slug . ".html";

$htmlContent = '<!DOCTYPE html>
<html lang="es">

<head>
  <!-- Google tag (gtag.js) -->
  <script async src="https://www.googletagmanager.com/gtag/js?id=G-ZN6YXDNHMR"></script>
  <script>
    window.dataLayer = window.dataLayer || [];
    function gtag() { dataLayer.push(arguments); }
    gtag(\'js\', new Date());
    gtag(\'config\', \'G-ZN6YXDNHMR\');
  </script>
  <meta charset="utf-8" />
  <meta content="width=device-width, initial-scale=1.0" name="viewport" />
  <title>' . htmlspecialchars($title) . ' | Energe</title>
  <meta content="' . htmlspecialchars($metaDesc) . '" name="description" />
  <meta content="energía solar Argentina, paneles solares, ' . htmlspecialchars($catLabel) . ', ahorro energía" name="keywords" />
  <meta content="index, follow" name="robots" />
  <link href="' . $canonicalUrl . '" rel="canonical" />
  <meta content="article" property="og:type" />
  <meta content="' . htmlspecialchars($title) . ' | Energe" property="og:title" />
  <meta content="' . htmlspecialchars($excerpt) . '" property="og:description" />
  <meta content="' . htmlspecialchars($heroImage) . '" property="og:image" />
  <meta content="' . $canonicalUrl . '" property="og:url" />
  <meta content="summary_large_image" name="twitter:card" />
  <link href="https://fonts.googleapis.com" rel="preconnect" />
  <link crossorigin="" href="https://fonts.gstatic.com" rel="preconnect" />
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet" />
  <link href="images/favicon.png" rel="icon" type="image/png" />
  <link href="style.css?v=1.01" rel="stylesheet" />
  <link href="chatbot.css" rel="stylesheet" />
  <script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@type": "Article",
    "headline": "' . htmlspecialchars($title) . '",
    "description": "' . htmlspecialchars($excerpt) . '",
    "image": "' . htmlspecialchars($heroImage) . '",
    "datePublished": "' . date('Y-m-d') . '",
    "dateModified": "' . date('Y-m-d') . '",
    "author": {"@type": "Organization", "name": "Energe", "url": "https://energe.com.ar"},
    "publisher": {
      "@type": "Organization",
      "name": "Energe",
      "logo": {"@type": "ImageObject", "url": "https://energe.com.ar/images/logos/logo-energe.png"}
    },
    "mainEntityOfPage": {"@type": "WebPage", "@id": "' . $canonicalUrl . '"},
    "keywords": "energía solar, paneles solares, ' . htmlspecialchars($catLabel) . '"
  }
  </script>
</head>

<body class="article-page">
  <div class="mockup-bar">Especialistas en soluciones Fotovoltaicas Industriales en Argentina</div>
  <nav>
    <div class="nav-logo"><a href="./"><img alt="Energe" src="images/logos/logo-energe.png" width="150" height="40" /></a></div>
    <button aria-label="Abrir menú" class="nav-hamburger" id="navToggle">
      <span></span><span></span><span></span>
    </button>
    <ul class="nav-links" id="navLinks">
      <li class="nav-dropdown">
        <a href="#soluciones">SOLUCIONES <span class="dd-arrow">▾</span></a>
        <ul class="nav-dropdown-menu">
          <li><a href="soluciones-ongrid.html">SOLAR ON-GRID</a></li>
          <li><a href="soluciones-offgrid-backup.html">OFF-GRID / BACKUP INDUSTRIAL</a></li>
        </ul>
      </li>
      <li><a href="index.html#obras">OBRAS REALIZADAS</a></li>
      <li><a href="blog.html">BLOG</a></li>
      <li><a href="preguntas-frecuentes.html">FAQ</a></li>
      <li><a href="contacto.html">CONTACTO</a></li>
      <li><a class="nav-cta" href="cotizador-solar.html">COTIZÁ TU PROYECTO</a></li>
      <li class="nav-lang-li"><a class="lang-btn active" href="./">ES</a><span class="lang-sep">|</span><a class="lang-btn" href="en/">EN</a></li>
    </ul>
  </nav>

  <!-- HERO -->
  <div class="article-hero">
    <img alt="' . htmlspecialchars($title) . '" src="' . htmlspecialchars($heroImage) . '" width="1200" height="630" />
    <div class="article-hero-overlay"></div>
    <div class="article-hero-content">
      <nav aria-label="Breadcrumb" class="article-breadcrumb">
        <a href="./">Home</a>
        <span>›</span>
        <a href="blog.html">Blog</a>
        <span>›</span>
        <span>' . htmlspecialchars($catLabel) . '</span>
      </nav>
      <div class="article-hero-cat" style="background:#d4721a">' . htmlspecialchars($catLabel) . '</div>
      <h1 class="article-hero-title">' . htmlspecialchars($title) . '</h1>
    </div>
  </div>

  <!-- CUERPO -->
  <div class="article-wrap">
    <main class="article-main">
      <div class="article-meta-bar">
        <div class="article-author">
          <div class="author-avatar">E</div>
          <div>
            <div class="author-name">' . htmlspecialchars($author) . '</div>
            <div class="author-role">Redacción Energe</div>
          </div>
        </div>
        <span class="article-date">' . $currentDate . '</span>
        <span class="article-reading">' . htmlspecialchars($readTime) . ' de lectura</span>
      </div>

      <div class="article-body">
        <p class="excerpt">' . htmlspecialchars($excerpt) . '</p>
' . $bodyHtml . '
      </div>

      <div class="article-share">
        <span class="article-share-label">Compartir</span>
        <div class="article-share-btns">
          <a class="share-btn" href="mailto:?subject=' . rawurlencode($title) . '&body=' . rawurlencode($canonicalUrl) . '" title="Compartir por email">
            <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
              <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z" />
              <polyline points="22,6 12,13 2,6" />
            </svg>
          </a>
          <a class="share-btn" href="https://api.whatsapp.com/send?text=' . rawurlencode($title . ' ' . $canonicalUrl) . '" rel="noopener" target="_blank" title="Compartir por WhatsApp">
            <svg fill="currentColor" viewBox="0 0 24 24">
              <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z" />
              <path d="M12 0C5.373 0 0 5.373 0 12c0 2.127.558 4.122 1.532 5.856L.057 23.882a.5.5 0 0 0 .606.61l6.162-1.615A11.945 11.945 0 0 0 12 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 22c-1.891 0-3.659-.523-5.168-1.432l-.37-.22-3.83 1.004.979-3.713-.241-.379A9.945 9.945 0 0 1 2 12C2 6.477 6.477 2 12 2s10 4.477 10 10-4.477 10-10 10z" />
            </svg>
          </a>
        </div>
      </div>
      <div class="article-tags">
' . implode("\n", array_map(function($t) { return '        <a class="article-tag" href="blog.html">' . htmlspecialchars(trim($t)) . '</a>'; }, $tags)) . '
      </div>
    </main>

    <!-- SIDEBAR -->
    <aside class="article-sidebar">
      <div class="sidebar-cta-widget">
        <div class="sidebar-cta-ico">💼</div>
        <div class="sidebar-cta-title">' . htmlspecialchars($sidebarCtaTitle) . '</div>
        <div class="sidebar-cta-sub">' . htmlspecialchars($sidebarCtaSub) . '</div>
        <a class="sidebar-cta-btn" href="cotizador-solar.html">Consultar ahora →</a>
      </div>
      <div class="sidebar-widget">
        <div class="sidebar-widget-title">Categorías</div>
        <ul class="sidebar-cats">
          <li class="sidebar-cat-item"><a href="blog.html"><span>Marco Legal</span><span class="sidebar-cat-count">2</span></a></li>
          <li class="sidebar-cat-item"><a href="blog.html"><span>Finanzas & ROI</span><span class="sidebar-cat-count">2</span></a></li>
          <li class="sidebar-cat-item"><a href="blog.html"><span>Industria & Off-Grid</span><span class="sidebar-cat-count">1</span></a></li>
        </ul>
      </div>
    </aside>
  </div>

  <!-- FULL CTA -->
  <section class="article-full-cta">
    <div class="af-cta-inner">
      <div class="af-cta-content">
        <div class="af-cta-tag">' . htmlspecialchars($fullCtaTag) . '</div>
        <h2 class="af-cta-title">' . $fullCtaTitle . '</h2>
        <p class="af-cta-desc">' . htmlspecialchars($fullCtaDesc) . '</p>
      </div>
      <a href="cotizador-solar.html" class="af-cta-btn">' . htmlspecialchars($ctaButtonText) . '</a>
    </div>
  </section>

  <!-- ARTÍCULOS RELACIONADOS -->
  <div class="article-related">
    <div class="article-related-title">Artículos relacionados</div>
    <div class="article-related-grid">
      <a class="bcard" href="blog-aumento-tarifas-industriales-energia-solar.html">
        <div class="bcard-img">
          <img alt="Aumento de tarifas industriales" loading="lazy" src="images/blog-tarifas-solar.jpg" width="600" height="400" />
          <span class="bcard-cat cat-roi">Finanzas & ROI</span>
        </div>
        <div class="bcard-body">
          <div class="bcard-meta">
            <span class="bcard-date">14 May 2026</span>
            <span class="bcard-read">8 min</span>
          </div>
          <h3 class="bcard-title">Aumento de tarifas en industrias: por qué la energía solar es tu mejor defensa</h3>
        </div>
      </a>
      <a class="bcard" href="blog-roi-solar-industrial.html">
        <div class="bcard-img">
          <img alt="ROI energía solar industrial" loading="lazy" src="images/obras/bodega-trivento.jpeg" />
          <span class="bcard-cat cat-roi">Finanzas & ROI</span>
        </div>
        <div class="bcard-body">
          <div class="bcard-meta">
            <span class="bcard-date">22 Ene 2025</span>
            <span class="bcard-read">9 min</span>
          </div>
          <h3 class="bcard-title">¿Cuánto tarda en recuperarse la inversión en energía solar industrial?</h3>
        </div>
      </a>
    </div>
  </div>

  <!-- Footer -->
  <footer>
    <div class="ft-main">
      <div class="ft-col ft-brand">
        <div class="ft-logo"><img alt="Energe" src="images/logos/logo-energe-footer.png" width="120" height="32" /></div>
        <p class="ft-desc"><strong>Somos</strong> una empresa dedicada al desarrollo de soluciones de energía solar térmica y fotovoltaica. Desde 2007, impulsamos el ahorro energético y el cuidado del ambiente.</p>
      </div>
      <div class="ft-col">
        <div class="ft-col-title">Contacto</div>
        <ul class="ft-contact-list">
          <li>
            <svg class="ft-icon" fill="currentColor" viewBox="0 0 24 24">
              <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"></path>
              <path d="M12 0C5.373 0 0 5.373 0 12c0 2.127.558 4.122 1.532 5.856L.057 23.882a.5.5 0 0 0 .606.61l6.162-1.615A11.945 11.945 0 0 0 12 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 22c-1.891 0-3.659-.523-5.168-1.432l-.37-.22-3.83 1.004.979-3.713-.241-.379A9.945 9.945 0 0 1 2 12C2 6.477 6.477 2 12 2s10 4.477 10 10-4.477 10-10 10z"></path>
            </svg>
            <span>261 242 4493</span>
          </li>
          <li>
            <svg class="ft-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path>
              <polyline points="22,6 12,13 2,6"></polyline>
            </svg>
            <span>contacto@energe.com.ar</span>
          </li>
          <li>
            <svg class="ft-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
              <circle cx="12" cy="10" r="3"></circle>
            </svg>
            <a href="https://www.google.com/maps/dir/-32.9449472,-68.7767552/Energe+Mendoza,+Galpon+Interno,+Alsina+2550,+M5511+Maip%C3%BA,+Mendoza/@-32.9433071,-68.7861379,16z/data=!3m1!4b1!4m9!4m8!1m1!4e1!1m5!1m1!1s0x967e0c3f7917f36f:0xbcb6b78f61739d39!2m2!1d-68.7855175!2d-32.9415981?entry=ttu&g_ep=EgoyMDI2MDQxNS4wIKXMDSoASAFQAw%3D%3D" rel="noopener" style="color:inherit;text-decoration:none;" target="_blank">Alsina 2550, Maipú, Mendoza</a>
          </li>
        </ul>
      </div>
      <div class="ft-col">
        <div class="ft-col-title">Accesos</div>
        <ul class="ft-nav-list">
          <li><a href="./">Home</a></li>
          <li><a href="soluciones-ongrid.html">Solar On-Grid</a></li>
          <li><a href="soluciones-offgrid-backup.html">Off-Grid / Backup</a></li>
          <li><a href="blog.html">Blog</a></li>
          <li><a href="preguntas-frecuentes.html">FAQ</a></li>
          <li><a href="contacto.html">Contacto</a></li>
          <li><a href="soporte.html">Servicio Postventa</a></li>
        </ul>
      </div>
      <div class="ft-col">
        <div class="ft-col-title">Redes sociales</div>
        <ul class="ft-social-list">
          <li><a href="https://www.facebook.com/energe.energiarenovable" rel="noopener" target="_blank"><svg class="ft-icon" fill="currentColor" viewBox="0 0 24 24"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"></path></svg><span>energe.energiarenovable</span></a></li>
          <li><a href="https://www.instagram.com/energe.ar" rel="noopener" target="_blank"><svg class="ft-icon" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 1 0 0 12.324 6.162 6.162 0 0 0 0-12.324zM12 16a4 4 0 1 1 0-8 4 4 0 0 1 0 8zm6.406-11.845a1.44 1.44 0 1 0 0 2.881 1.44 1.44 0 0 0 0-2.881z"></path></svg><span>@energe.ar</span></a></li>
          <li><a href="https://www.linkedin.com/company/energe" rel="noopener" target="_blank"><svg class="ft-icon" fill="currentColor" viewBox="0 0 24 24"><path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.062 2.062 0 0 1-2.063-2.065 2.064 2.064 0 1 1 2.063 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"></path></svg><span>energe</span></a></li>
          <li><a href="https://www.youtube.com/@energe_sa" rel="noopener" target="_blank"><svg class="ft-icon" fill="currentColor" viewBox="0 0 24 24"><path d="M23.495 6.205a3.007 3.007 0 0 0-2.088-2.088c-1.87-.501-9.396-.501-9.396-.501s-7.507-.01-9.396.501A3.007 3.007 0 0 0 .527 6.205a31.247 31.247 0 0 0-.522 5.805 31.247 31.247 0 0 0 .522 5.783 3.007 3.007 0 0 0 2.088 2.088c1.868.502 9.396.502 9.396.502s7.506 0 9.396-.502a3.007 3.007 0 0 0 2.088-2.088 31.247 31.247 0 0 0 .5-5.783 31.247 31.247 0 0 0-.5-5.805zM9.609 15.601V8.408l6.264 3.602z"></path></svg><span>@energe_sa</span></a></li>
        </ul>
      </div>
    </div>
    <div class="ft-bottom">
      <span><a href="privacidad.html">Política de privacidad</a></span>
      <span><a href="legales.html">Legales</a></span>
    </div>
  </footer>
  <script>
    (function(){var btn=document.getElementById(\'navToggle\'),nav=document.getElementById(\'navLinks\');if(btn&&nav){btn.addEventListener(\'click\',function(){var o=nav.classList.toggle(\'nav-open\');btn.classList.toggle(\'is-open\',o);});nav.querySelectorAll(\'a\').forEach(function(a){a.addEventListener(\'click\',function(){nav.classList.remove(\'nav-open\');btn.classList.remove(\'is-open\');});});}})();
  </script>
  <script src="chatbot.js"></script>
</body>

</html>';

// Escribir el archivo del artículo
$dirName = basename(__DIR__);
if ($dirName === 'api') {
    $filename = "../blog-" . $slug . ".html";
} else {
    $filename = "./blog-" . $slug . ".html";
}

$writeResult = file_put_contents($filename, $htmlContent);

if ($writeResult === false) {
    header('Content-Type: application/json');
    echo json_encode(["ok" => false, "error" => "No se pudo escribir el archivo del artículo: " . $filename]);
    exit;
}

// 7. Inyección Automática en blog.html (Listado general de notas)
if ($dirName === 'api') {
    $blogListFile = "../blog.html";
} else {
    $blogListFile = "./blog.html";
}

$blogUpdateSuccess = false;
if (file_exists($blogListFile)) {
    $blogHtml = file_get_contents($blogListFile);
    
    // Evitar tarjetas duplicadas si se republica el mismo artículo
    $cardHref = "blog-" . $slug . ".html";
    if (strpos($blogHtml, $cardHref) === false) {
        // Formatear la fecha en español para la tarjeta (ej: 21 May 2026)
        $monthsAbbr = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
        $cardDate = date('d ') . $monthsAbbr[date('n') - 1] . date(' Y');
        
        $imageUrl = $image ? $image : 'images/hero-drone.jpg';
        
        // Estructura idéntica a las tarjetas del listado real
        $cardHtml = "  <a class=\"bcard\" data-cat=\"" . htmlspecialchars($category) . "\" href=\"" . htmlspecialchars($cardHref) . "\">\n";
        $cardHtml .= "    <div class=\"bcard-img\">\n";
        $cardHtml .= "      <img alt=\"" . htmlspecialchars($title) . "\" loading=\"lazy\" src=\"" . htmlspecialchars($imageUrl) . "\" width=\"600\" height=\"400\"/>\n";
        $cardHtml .= "      <span class=\"bcard-cat " . htmlspecialchars($catClass) . "\"" . $catStyle . ">" . htmlspecialchars($catLabel) . "</span>\n";
        $cardHtml .= "    </div>\n";
        $cardHtml .= "    <div class=\"bcard-body\">\n";
        $cardHtml .= "      <div class=\"bcard-meta\">\n";
        $cardHtml .= "        <span class=\"bcard-date\">" . htmlspecialchars($cardDate) . "</span>\n";
        $cardHtml .= "        <span class=\"bcard-read\">" . htmlspecialchars($readTime) . "</span>\n";
        $cardHtml .= "      </div>\n";
        $cardHtml .= "      <h2 class=\"bcard-title\">" . htmlspecialchars($title) . "</h2>\n";
        $cardHtml .= "      <p class=\"bcard-excerpt\">" . htmlspecialchars($excerpt) . "</p>\n";
        $cardHtml .= "    </div>\n";
        $cardHtml .= "  </a>\n";
        
        // Inyectar al principio de <div class="blog-index-grid" id="blogGrid">
        $targetTag = '<div class="blog-index-grid" id="blogGrid">';
        $pos = strpos($blogHtml, $targetTag);
        if ($pos !== false) {
            $insertPos = $pos + strlen($targetTag);
            $updatedBlogHtml = substr($blogHtml, 0, $insertPos) . "\n" . $cardHtml . substr($blogHtml, $insertPos);
            file_put_contents($blogListFile, $updatedBlogHtml);
            $blogUpdateSuccess = true;
        }
    } else {
        // Si ya existe, se actualizó la nota pero la tarjeta del índice se mantiene intacta
        $blogUpdateSuccess = true;
    }
}

// Retornar éxito
header('Content-Type: application/json');
echo json_encode([
    "ok" => true,
    "url" => $canonicalUrl,
    "slug" => $slug,
    "index_updated" => $blogUpdateSuccess
]);
exit;
