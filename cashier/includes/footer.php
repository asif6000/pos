</div>
</main>
</div>

<script src="<?php echo htmlspecialchars(assetUrl('assets/js/app.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
<?php if (isset($pageScripts)): ?>
    <?php foreach ($pageScripts as $script): ?>
        <script src="<?php echo $script; ?>"></script>
    <?php endforeach; ?>
<?php endif; ?>
</body>

</html>
