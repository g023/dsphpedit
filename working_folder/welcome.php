<?php
// A small dynamic sample to preview server-side.
$now = date('Y-m-d H:i:s');
$nums = range(1, 5);
?>
<!doctype html>
<html><head><meta charset="utf-8"><title>Welcome</title>
<style>body{font-family:system-ui;padding:2rem;background:#f5f7fa;color:#222}
h1{color:#0e639c} .card{background:#fff;padding:1rem 1.5rem;border-radius:10px;box-shadow:0 2px 10px #0001;display:inline-block}</style>
</head><body>
<div class="card">
  <h1>Hello from PHP <?= PHP_VERSION ?></h1>
  <p>Server time: <strong><?= htmlspecialchars($now) ?></strong></p>
  <p>Squares:
  <?php foreach ($nums as $n): ?>
    <?= $n ?>&sup2;=<?= $n*$n ?><?= $n < 5 ? ', ' : '' ?>
  <?php endforeach; ?>
  </p>
</div>
</body></html>
