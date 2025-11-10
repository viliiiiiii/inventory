<?php
require_once __DIR__ . '/../helpers.php';
require_login();
require_root();


$errors = [];
$ok     = null;

// Whitelist of editable settings (key => [type, label, placeholder, help])
$FIELDS = [
  // Branding
  'app.title'        => ['string','App Title',        'Punchlist Pro', 'Shown in navbar & titles'],
  'brand.theme'      => ['string','Theme Color (hex)', '#0ea5e9',      'Used for PWA/theme-color'],
  // Behavior
  'ui.default_view'  => ['string','Default Tasks View','table',        'table | cards | sticky'],
  'ui.page_size'     => ['int',   'Default Page Size', '30',           'Rows per page'],
  'maintenance.mode' => ['bool',  'Maintenance Mode',  '',             'If on, non-root users see a maintenance screen'],
  // Security
  'auth.2fa_required'=> ['bool',  'Require 2FA (admins)','',           'Enforce TOTP for admin/root'],
  // Email
  'smtp.host'        => ['string','SMTP Host',   '',  'Mail server host'],
  'smtp.port'        => ['int',   'SMTP Port',   '587','Usually 587'],
  'smtp.user'        => ['string','SMTP User',   '',  'Username/email'],
  'smtp.pass'        => ['secret','SMTP Password','', 'Stored as secret'],
  // Export / QR defaults
  'qr.default_ttl'   => ['int',   'QR Token TTL (days)','90','Default validity for public tokens'],
  'qr.default_size'  => ['int',   'QR Image Size (px)', '140','For PDF exports that render QR'],
];

if (is_post()) {
  if (!verify_csrf_token($_POST[CSRF_TOKEN_NAME] ?? null)) {
    $errors[] = 'Invalid CSRF token.';
  } else {
    try {
      foreach ($FIELDS as $key => [$type, $label]) {
        if (!array_key_exists($key, $_POST)) continue;

        $raw = $_POST[$key];
        switch ($type) {
          case 'int':
            $val = (int)$raw;
            break;
          case 'bool':
            $val = (isset($raw) && ($raw === '1' || $raw === 'on' || $raw === 'true'));
            break;
          case 'json':
            $decoded = json_decode((string)$raw, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
              $errors[] = "Invalid JSON for {$label}.";
              continue 2;
            }
            $val = $decoded;
            break;
          case 'secret':
            // If left blank, do not overwrite existing value
            if ($raw === '') continue 2;
            $val = (string)$raw;
            break;
          default:
            $val = trim((string)$raw);
        }
        setting_set($key, $val, $type);
      }
      // Handle logo upload (optional)
      if (!empty($_FILES['brand_logo']['name']) && $_FILES['brand_logo']['error'] === UPLOAD_ERR_OK) {
        $tmp = $_FILES['brand_logo']['tmp_name'];
        $info = @getimagesize($tmp);
        if (!$info) {
          $errors[] = 'Uploaded logo is not an image.';
        } else {
          $ext = image_type_to_extension($info[2], false); // e.g. png
          if (!in_array(strtolower($ext), ['png','jpg','jpeg','webp'], true)) {
            $errors[] = 'Logo must be PNG/JPG/WEBP.';
          } else {
            $dir = __DIR__ . '/../uploads/brand';
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            $name = 'logo.' . $ext;
            $dest = $dir . '/' . $name;
            if (!@move_uploaded_file($tmp, $dest)) {
              $errors[] = 'Failed to save logo.';
            } else {
              // Save public path; bust cache with timestamp
              $public = '/uploads/brand/' . $name . '?v=' . time();
              setting_set('brand.logo_url', $public, 'string');
            }
          }
        }
      }

      if (!$errors) {
        $ok = 'Settings saved.';
      }
    } catch (Throwable $e) {
      $errors[] = 'Server error while saving settings.';
    }
  }
}

$title = 'Site Settings';
include __DIR__ . '/../includes/header.php';

// Helper for printing values with types
function field_value(string $key, string $type): string {
  $v = setting_get($key, '');
  if ($type === 'bool')    return $v ? '1' : '';
  if ($type === 'json')    return json_encode($v, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
  if ($type === 'secret')  return ''; // never pre-fill secrets
  return (string)$v;
}
?>

<section class="card">
  <div class="card-header">
    <h1>Site Settings</h1>
    <div class="actions">
      <?php if ($ok): ?><span class="badge"><?php echo sanitize($ok); ?></span><?php endif; ?>
    </div>
  </div>

  <?php if ($errors): ?>
    <div class="flash flash-error"><?php echo sanitize(implode(' ', $errors)); ?></div>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data" class="grid two">
    <h2 class="field-span-2">Branding</h2>
    <label>App Title
      <input type="text" name="app.title" value="<?php echo sanitize(field_value('app.title','string')); ?>">
    </label>
    <label>Theme Color
      <input type="text" name="brand.theme" value="<?php echo sanitize(field_value('brand.theme','string')); ?>" placeholder="#0ea5e9">
    </label>
    <label class="field-span-2">Logo (PNG/JPG/WEBP)
      <input type="file" name="brand_logo" accept="image/*">
      <?php $logo = setting_get('brand.logo_url',''); if ($logo): ?>
        <div class="muted small">Current: <code><?php echo sanitize($logo); ?></code></div>
      <?php endif; ?>
    </label>

    <h2 class="field-span-2">Behavior</h2>
    <label>Default Tasks View
      <select name="ui.default_view">
        <?php
          $dv = setting_get('ui.default_view','table');
          foreach (['table','cards','sticky'] as $opt) {
            $sel = ($dv === $opt) ? 'selected' : '';
            echo "<option value=\"{$opt}\" {$sel}>$opt</option>";
          }
        ?>
      </select>
    </label>
    <label>Default Page Size
      <input type="number" name="ui.page_size" min="5" max="500" value="<?php echo sanitize(field_value('ui.page_size','int')); ?>">
    </label>
    <label>
      <input type="checkbox" name="maintenance.mode" value="1" <?php echo setting_get('maintenance.mode', false, 'bool') ? 'checked' : ''; ?>>
      Maintenance Mode (show non-root users a maintenance screen)
    </label>

    <h2 class="field-span-2">Security</h2>
    <label>
      <input type="checkbox" name="auth.2fa_required" value="1" <?php echo setting_get('auth.2fa_required', false, 'bool') ? 'checked' : ''; ?>>
      Require 2FA for admins
    </label>

    <h2 class="field-span-2">Email (SMTP)</h2>
    <label>SMTP Host
      <input type="text" name="smtp.host" value="<?php echo sanitize(field_value('smtp.host','string')); ?>">
    </label>
    <label>SMTP Port
      <input type="number" name="smtp.port" value="<?php echo sanitize(field_value('smtp.port','int')); ?>">
    </label>
    <label>SMTP User
      <input type="text" name="smtp.user" value="<?php echo sanitize(field_value('smtp.user','string')); ?>">
    </label>
    <label>SMTP Password (leave blank to keep existing)
      <input type="password" name="smtp.pass" value="">
    </label>

    <h2 class="field-span-2">Export / QR defaults</h2>
    <label>QR Token TTL (days)
      <input type="number" name="qr.default_ttl" min="1" max="365" value="<?php echo sanitize(field_value('qr.default_ttl','int')); ?>">
    </label>
    <label>QR Image Size (px)
      <input type="number" name="qr.default_size" min="120" max="300" value="<?php echo sanitize(field_value('qr.default_size','int')); ?>">
    </label>

    <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo csrf_token(); ?>">
    <div class="form-actions field-span-2">
      <button class="btn primary" type="submit">Save Settings</button>
    </div>
  </form>
</section>

<?php include __DIR__ . '/../includes/footer.php';
