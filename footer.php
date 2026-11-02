<footer class="site-footer">
    <div class="footer-inner">
        <a class="brand brand-light" href="index.php"><span class="brand-mark">h</span>habita<span class="brand-dot">.</span></a>
        <p>Les belles histoires commencent chez soi.</p>
        <span>© <?= date('Y') ?> Habita · Votre prochain chez-vous.</span>
    </div>
</footer>
<?php $assetPrefix = $assetPrefix ?? ''; ?>
<script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous" defer></script>
<script src="<?= e($assetPrefix) ?>js/favorite-ajax.js" defer></script>
<script src="<?= e($assetPrefix) ?>bootstrap-5.2.3-dist/js/bootstrap.bundle.min.js" defer></script>
