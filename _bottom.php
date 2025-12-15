</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
	$(function() {
		const themeToggle = $('#theme-toggle');
		const html = $('html');

		// Toggle theme on click
		themeToggle.on('click', function() {
			const currentTheme = html.attr('data-bs-theme');
			const newTheme = currentTheme === 'dark' ? 'light' : 'dark';

			html.attr('data-bs-theme', newTheme);
			localStorage.setItem('theme', newTheme);
		});
	});
</script>
</body>

</html>