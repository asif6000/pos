</div><!-- End .content -->
</main>
</div><!-- End .app-wrapper -->

<!-- Scripts -->
<script src="../assets/js/app.js"></script>
<?php if (isset($pageScripts)): ?>
    <?php foreach ($pageScripts as $script): ?>
        <script src="<?php echo $script; ?>"></script>
    <?php endforeach; ?>
<?php endif; ?>
</body>

</html>