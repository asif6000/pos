</div><!-- End .content -->
</main>
</div><!-- End .app-wrapper -->

<!-- Scripts -->
<!--
    assetUrl() puts the file's mtime on the URL. Without it a fixed script stays
    in the browser cache and the shop keeps running the old one - which is how a
    corrected app.js kept throwing an error that no longer existed in the source.
-->
<script src="<?php echo htmlspecialchars(assetUrl('assets/js/app.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
<?php if (isset($pageScripts)): ?>
    <?php foreach ($pageScripts as $script): ?>
        <script src="<?php
            // A page may pass a local path or a full URL. A local one is versioned
            // so a fixed page script is not masked by the cache either.
            echo htmlspecialchars(
                preg_match('#^([a-z][a-z0-9+.\-]*:)?//#i', $script) ? $script : assetUrl($script),
                ENT_QUOTES, 'UTF-8'
            );
        ?>"></script>
    <?php endforeach; ?>
<?php endif; ?>
</body>

</html>