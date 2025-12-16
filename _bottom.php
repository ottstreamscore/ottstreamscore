</main>

<footer class="text-end mt-1 mb-4 text-muted small">
	<div class="container">
		<a href="https://github.com/ottstreamscore/"
			target="_blank"
			class="text-muted text-decoration-none"
			title="View on GitHub">
			<i class="fab fa-github me-1"></i> GitHub
		</a>
	</div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
	// Theme toggle functionality
	document.getElementById('theme-toggle-link').addEventListener('click', function(e) {
		e.preventDefault();
		const currentTheme = document.documentElement.getAttribute('data-bs-theme');
		const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
		document.documentElement.setAttribute('data-bs-theme', newTheme);
		localStorage.setItem('theme', newTheme);
	});
</script>
</body>

</html>