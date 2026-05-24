<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?php echo esc_html($subject); ?></title>
  <style>
    body { margin: 0; padding: 0; background: #f8fafc; color: #0f172a; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; }
    .container { max-width: 640px; margin: 0 auto; background: #ffffff; }
    .header { padding: 24px; background: <?php echo esc_attr($brand['accent']); ?>; color: white; text-align: center; }
    .header h1 { font-size: 20px; margin: 0; }
    .content { padding: 32px 24px; font-size: 16px; line-height: 1.6; }
    .content h1, .content h2 { color: <?php echo esc_attr($brand['accent']); ?>; }
    .content p { margin: 0 0 16px; }
    .content a { color: <?php echo esc_attr($brand['accent']); ?>; }
    .footer { padding: 16px 24px 32px; font-size: 12px; color: #64748b; text-align: center; border-top: 1px solid #e2e8f0; }
    .footer a { color: #64748b; text-decoration: underline; }
  </style>
</head>
<body>
  <div class="container">
    <div class="header">
      <?php if (! empty($brand['logo_url'])) { ?>
        <img src="<?php echo esc_url($brand['logo_url']); ?>" alt="<?php echo esc_attr($brand['name']); ?>" />
      <?php } else { ?>
        <h1><?php echo esc_html($brand['name']); ?></h1>
      <?php } ?>
    </div>
    <div class="content"><?php echo $body; ?></div>
    <div class="footer">
      <p>
        <a href="<?php echo esc_url($view_in_browser_url); ?>">View in browser</a>
        ·
        <a href="<?php echo esc_url($unsubscribe_url); ?>">Unsubscribe</a>
      </p>
      <?php if (! empty($brand['physical_address'])) { ?>
        <p><?php echo esc_html($brand['physical_address']); ?></p>
      <?php } ?>
    </div>
  </div>
</body>
</html>
