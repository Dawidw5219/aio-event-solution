jQuery(document).ready(($) => {
	// Color picker sync
	$(".color-picker").on("input change", function () {
		const $input = $(this);
		const color = $input.val();
		$input.next('input[type="text"]').val(color);
	});

	// Date validation for event meta boxes
	$("#_aio_event_start_date").on("change", function () {
		const selectedDate = new Date($(this).val());
		const today = new Date();
		today.setHours(0, 0, 0, 0);

		if (selectedDate < today) {
			if (
				!confirm(
					aioEventsAdmin.i18n.confirmPastDate ||
						"This event is in the past. Are you sure?",
				)
			) {
				$(this).val("");
			}
		}
	});

	// Live preview for settings
	if ($(".color-picker").length) {
		const $preview = $('<div class="color-preview-panel"></div>');
		$("form").after($preview);

		$(".color-picker").on("input", () => {
			const primaryColor = $("#primary_color").val();
			const secondaryColor = $("#secondary_color").val();

			// Update CSS variables instantly
			document.documentElement.style.setProperty(
				"--aio-events-color-primary",
				primaryColor,
			);
			document.documentElement.style.setProperty(
				"--aio-events-color-secondary",
				secondaryColor,
			);
		});
	}

	// Confirm delete
	$(".delete-event").on("click", (e) => {
		if (!confirm(aioEventsAdmin.i18n.confirmDelete || "Are you sure?")) {
			e.preventDefault();
		}
	});
});
