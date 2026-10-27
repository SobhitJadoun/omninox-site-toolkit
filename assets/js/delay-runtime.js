(function () {
	var loaded = false;
	var events = ['mousemove', 'keydown', 'touchstart', 'scroll', 'wheel'];

	function load() {
		if (loaded) { return; }
		loaded = true;
		events.forEach(function (e) { window.removeEventListener(e, load, { passive: true }); });

		document.querySelectorAll('script[data-omninox-src], script[data-wpmt-src]').forEach(function (node) {
			var script = document.createElement('script');
			script.src = node.getAttribute('data-omninox-src') || node.getAttribute('data-wpmt-src');
			script.async = false;
			document.body.appendChild(script);
		});
	}

	events.forEach(function (e) { window.addEventListener(e, load, { passive: true }); });
	setTimeout(load, 6000);
})();
