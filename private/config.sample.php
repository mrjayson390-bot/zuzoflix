<?php
/* Copy to config.php on the server and fill it in there. Never paste keys into chat. */
return [
  'app' => [
    'name'      => 'Zuzoflix',
    'url'       => 'https://FILL_IN_DOMAIN',
    'env'       => 'production',
    'whatsapp'  => '',                    // digits only, e.g. 2567XXXXXXXX (shows the round WhatsApp button when set)
    'takedown_email' => '',               // shown in the footer when set
    'payments_enabled' => false,          // prices come later; while false every film is playable
    'secret'    => 'FILL_IN_ON_SERVER',   // 48+ random characters; signs playback tokens. Make it with: openssl rand -hex 32
  ],
  // Film details lookup (TMDB). Off until you add a key. Free TMDB access is non-commercial: a site that sells ads
  // needs TMDB's commercial licence. Poster import stays false unless you have the right to use those posters.
  'tmdb' => ['api_key' => 'FILL_IN_ON_SERVER', 'import_images' => false],
  'db' => [
    'driver' => 'mysql',                  // 'mysql' on the server, 'sqlite' for local tests
    'host' => 'localhost', 'name' => 'zuzoflix', 'user' => 'zuzoflix', 'pass' => 'FILL_IN_ON_SERVER',
  ],
  // Cloudflare R2: a bucket just for Zuzoflix, and a token that works on that bucket only.
  'r2' => [
    'account_id' => 'FILL_IN_ON_SERVER', 'bucket' => 'zuzoflix-media',
    'access_key_id' => 'FILL_IN_ON_SERVER', 'secret_access_key' => 'FILL_IN_ON_SERVER',
  ],
  'upload' => ['max_video_mb' => 8192, 'part_mb' => 16],
];
