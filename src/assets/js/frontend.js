jQuery(document).ready(($) => {
	// Event Registration Form
	$("#aio-event-registration-form").on("submit", function (e) {
		e.preventDefault();

		const $form = $(this);
		const $submitBtn = $form.find('button[type="submit"]');
		const $btnText = $submitBtn.find(".aio-btn-text");
		const $btnLoader = $submitBtn.find(".aio-btn-loader");
		const $messages = $(".aio-registration-messages");

		// Clear previous messages
		$messages.empty();

		// Get form data
		const formData = $form.serialize() + "&action=aio_register_event";

		// Disable submit button
		$submitBtn.prop("disabled", true);
		$btnText.hide();
		$btnLoader.show();

		$.ajax({
			url: aioEvents.ajaxUrl,
			type: "POST",
			data: formData,
			success: (response) => {
				if (response.success) {
					// Show success message
					$messages.html(
						'<div class="aio-message aio-message-success">' +
							response.data.message +
							"</div>",
					);

					// Reset form
					$form[0].reset();

					// Scroll to message
					$("html, body").animate(
						{
							scrollTop: $messages.offset().top - 100,
						},
						500,
					);
				} else {
					// Show error message
					$messages.html(
						'<div class="aio-message aio-message-error">' +
							response.data.message +
							"</div>",
					);
				}
			},
			error: () => {
				$messages.html(
					'<div class="aio-message aio-message-error">' +
						aioEvents.i18n.error +
						": " +
						"An unexpected error occurred. Please try again." +
						"</div>",
				);
			},
			complete: () => {
				// Re-enable submit button
				$submitBtn.prop("disabled", false);
				$btnText.show();
				$btnLoader.hide();
			},
		});
	});

	// Event card hover effects
	$(".event-card")
		.on("mouseenter", function () {
			$(this).addClass("scale-105");
		})
		.on("mouseleave", function () {
			$(this).removeClass("scale-105");
		});

	// Smooth scroll to anchor links
	$('a[href^="#"]').on("click", function (e) {
		const target = $(this.getAttribute("href"));
		if (target.length) {
			e.preventDefault();
			$("html, body")
				.stop()
				.animate(
					{
						scrollTop: target.offset().top - 100,
					},
					800,
				);
		}
	});

	// RSVP functionality (if enabled)
	$(".aio-event-rsvp-button").on("click", function (e) {
		e.preventDefault();
		const $button = $(this);
		const eventId = $button.data("event-id");

		$.ajax({
			url: aioEvents.ajaxUrl,
			type: "POST",
			data: {
				action: "aio_event_rsvp",
				nonce: aioEvents.nonce,
				event_id: eventId,
			},
			beforeSend: () => {
				$button.prop("disabled", true).text("Zapisywanie...");
			},
			success: (response) => {
				if (response.success) {
					$button.text("Zapisano!").addClass("bg-green-500");
					setTimeout(() => {
						$button
							.prop("disabled", false)
							.text("RSVP")
							.removeClass("bg-green-500");
					}, 2000);
				}
			},
			error: () => {
				$button.prop("disabled", false).text("Błąd - spróbuj ponownie");
			},
		});
	});

	// Hide country field container (honeypot-style hidden field)
	const hideCountryField = (form) => {
		const countryField = form.querySelector(
			'input[name="COUNTRY"], input[id="COUNTRY"]',
		);

		if (!countryField) {
			return;
		}

		// Find the container div (parent of .sib-form-block)
		const sibFormBlock = countryField.closest(".sib-form-block");
		if (sibFormBlock && sibFormBlock.parentElement) {
			sibFormBlock.parentElement.style.display = "none";
			console.log("[AIO Events] Country field container hidden");
		}
	};

	// Auto-detect and fill country field
	const autoFillCountry = (form) => {
		const countryField = form.querySelector(
			'input[name="COUNTRY"], input[id="COUNTRY"]',
		);

		if (!countryField) {
			console.log("[AIO Events] Country field not found in form");
			return;
		}

		console.log("[AIO Events] Country field found, detecting country...");

		// Try to get country from browser language first (faster)
		const browserLang = navigator.language || navigator.userLanguage;
		const countryFromLang = browserLang.split("-")[1]?.toUpperCase();

		if (countryFromLang && countryFromLang.length === 2) {
			countryField.value = countryFromLang;
			console.log(
				"[AIO Events] Country detected from browser language:",
				countryFromLang,
			);
			return;
		}

		console.log(
			"[AIO Events] Browser language detection failed, trying IP geolocation...",
		);

		// Fallback: Use IP geolocation API
		fetch("https://ipapi.co/json/")
			.then((response) => response.json())
			.then((data) => {
				if (data.country_code && data.country_code.length === 2) {
					countryField.value = data.country_code.toUpperCase();
					console.log(
						"[AIO Events] Country detected from ipapi.co:",
						data.country_code.toUpperCase(),
					);
				} else {
					console.log(
						"[AIO Events] ipapi.co did not return valid country code",
					);
				}
			})
			.catch(() => {
				console.log("[AIO Events] ipapi.co failed, trying alternative API...");
				// Fallback to alternative API if first fails
				fetch("https://ip-api.com/json/?fields=countryCode")
					.then((response) => response.json())
					.then((data) => {
						if (data.countryCode && data.countryCode.length === 2) {
							countryField.value = data.countryCode.toUpperCase();
							console.log(
								"[AIO Events] Country detected from ip-api.com:",
								data.countryCode.toUpperCase(),
							);
						} else {
							console.log(
								"[AIO Events] ip-api.com did not return valid country code",
							);
						}
					})
					.catch(() => {
						console.log("[AIO Events] All country detection methods failed");
					});
			});
	};

	// Brevo Form - intercept and handle via our API (adds to Brevo + saves registration)
	(() => {
		// Block ALL requests to sibforms.com (Brevo's form endpoint)
		const originalXHROpen = XMLHttpRequest.prototype.open;
		XMLHttpRequest.prototype.open = function(method, url) {
			if (url && url.toString().includes('sibforms.com')) {
				console.log('[AIO Events] Blocked Brevo XHR:', url);
				this._blocked = true;
				return;
			}
			return originalXHROpen.apply(this, arguments);
		};
		const originalXHRSend = XMLHttpRequest.prototype.send;
		XMLHttpRequest.prototype.send = function() {
			if (this._blocked) return;
			return originalXHRSend.apply(this, arguments);
		};

		const checkForm = setInterval(() => {
			const originalForm = document.querySelector("#sib-form");
			if (!originalForm) return;

			clearInterval(checkForm);

			// Get wrapper before cloning
			const wrapper = originalForm.closest("#aio-events-brevo-form-wrapper");
			const eventId = wrapper?.dataset.eventId;

			if (!eventId) {
				console.error("[AIO Events] Event ID not found");
				return;
			}

			// CRITICAL: Clone form to remove ALL Brevo event listeners
			const form = originalForm.cloneNode(true);
			originalForm.parentNode.replaceChild(form, originalForm);

			// Auto-fill and hide country field (on cloned form)
			autoFillCountry(form);
			hideCountryField(form);

			// Now add our submit handler (no Brevo listeners exist)
			form.addEventListener("submit", (e) => {
				e.preventDefault();
				e.stopPropagation();

				const $form = $(form);
				const $wrapper = $(wrapper);
				const $submitBtn = $form.find(
					'button[type="submit"], input[type="submit"]',
				);
				const $formContainer = $wrapper.find(
					".aio-events-brevo-form-container",
				);
				const $successMessage = $wrapper.find(
					".aio-events-registration-success",
				);
				const $errorMessage = $wrapper.find(".aio-events-registration-error");

				// Collect form data using FormData (handles arrays correctly)
				const formData = new FormData(form);
				const data = {};

				for (const [key, value] of formData.entries()) {
					if (key === "email_address_check") continue;

					// Handle array fields (checkboxes with [])
					if (key.endsWith("[]")) {
						if (!data[key]) data[key] = [];
						data[key].push(value);
					} else {
						data[key] = value;
					}
				}

				console.log("[AIO Events] Form data:", data);

				// Show loading state
				$errorMessage.hide();
				const originalBtnHtml = $submitBtn.html();
				$submitBtn
					.prop("disabled", true)
					.html('<span class="aio-spinner"></span>');

				// Add spinner CSS if not exists
				if (!document.getElementById("aio-spinner-style")) {
					$("head").append(
						'<style id="aio-spinner-style">.aio-spinner{display:inline-block;width:16px;height:16px;border:2px solid rgba(255,255,255,0.3);border-top-color:#fff;border-radius:50%;animation:aio-spin 0.8s linear infinite}@keyframes aio-spin{to{transform:rotate(360deg)}}</style>',
					);
				}

				// Send to our API
				$.ajax({
					url: aioEvents.restUrl + "register?event_id=" + eventId,
					type: "POST",
					contentType: "application/json",
					data: JSON.stringify({
						event_id: parseInt(eventId),
						form_data: data,
					}),
					success: (response) => {
						if (response.success || response.already_registered) {
							// Show success
							$formContainer.slideUp(300);

							const email = data.EMAIL || data.email || "";
							if (email) {
								$successMessage
									.find(".aio-events-success-email-value")
									.text(email.trim());
							} else {
								$successMessage.find(".aio-events-success-email").hide();
							}

							$successMessage.slideDown(300);
							$("html, body").animate(
								{ scrollTop: $successMessage.offset().top - 100 },
								500,
							);
						} else {
							// Show error
							$errorMessage
								.find(".aio-events-error-message-text")
								.text(response.message || "Registration failed");
							$errorMessage.slideDown(300);
							$submitBtn.prop("disabled", false).html(originalBtnHtml);
						}
					},
					error: (xhr) => {
						const msg = xhr.responseJSON?.message || "An error occurred";
						$errorMessage.find(".aio-events-error-message-text").text(msg);
						$errorMessage.slideDown(300);
						$submitBtn.prop("disabled", false).html(originalBtnHtml);
					},
				});
			});

			console.log("[AIO Events] Form ready (API mode)");
		}, 100);

		setTimeout(() => clearInterval(checkForm), 10000);
	})();

	// Calendar buttons handler
	const initCalendarButtons = () => {
		const successContainer = document.querySelector(
			".aio-events-registration-success",
		);
		if (!successContainer) return;

		const eventTitle = successContainer.dataset.eventTitle || "";
		const eventSubtitle = successContainer.dataset.eventSubtitle || "";
		const utcStart = successContainer.dataset.utcStart || "";
		const utcEnd = successContainer.dataset.utcEnd || "";
		const calendarNotice =
			successContainer.dataset.calendarNotice ||
			"The event link will be sent to your registered email shortly before the event starts.";

		if (!utcStart || !utcEnd) return;

		// Build description - subtitle + notice about link
		const descriptionParts = [];
		if (eventSubtitle) descriptionParts.push(eventSubtitle);
		descriptionParts.push(calendarNotice);
		const description = descriptionParts.join("\n\n");

		// Google Calendar URL - UTC times with Z suffix
		const googleBtn = successContainer.querySelector(".aio-calendar-google");
		if (googleBtn) {
			const googleUrl = new URL("https://calendar.google.com/calendar/render");
			googleUrl.searchParams.set("action", "TEMPLATE");
			googleUrl.searchParams.set("text", eventTitle);
			googleUrl.searchParams.set("dates", utcStart + "/" + utcEnd);
			googleUrl.searchParams.set("details", description);
			googleBtn.href = googleUrl.toString();
			googleBtn.target = "_blank";
			googleBtn.rel = "noopener noreferrer";
		}

		// Outlook Calendar URL - UTC ISO format
		const outlookBtn = successContainer.querySelector(".aio-calendar-outlook");
		if (outlookBtn) {
			const outlookUrl = new URL(
				"https://outlook.live.com/calendar/0/action/compose",
			);
			// Convert YYYYMMDDTHHmmssZ to ISO format
			const toIso = (utc) => {
				const m = utc.match(/(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})Z/);
				return m ? `${m[1]}-${m[2]}-${m[3]}T${m[4]}:${m[5]}:${m[6]}Z` : utc;
			};
			outlookUrl.searchParams.set("subject", eventTitle);
			outlookUrl.searchParams.set("startdt", toIso(utcStart));
			outlookUrl.searchParams.set("enddt", toIso(utcEnd));
			outlookUrl.searchParams.set("body", description);
			outlookBtn.href = outlookUrl.toString();
			outlookBtn.target = "_blank";
			outlookBtn.rel = "noopener noreferrer";
		}

		// ICS file download - UTC with Z suffix
		const icsBtn = successContainer.querySelector(".aio-calendar-ics");
		if (icsBtn && !icsBtn.dataset.initialized) {
			icsBtn.dataset.initialized = "true";
			icsBtn.addEventListener("click", (e) => {
				e.preventDefault();

				// Get site name from document title (before separator)
				const siteName = (
					document.title.split(/[|\-–—]/).pop() || location.hostname
				)
					.trim()
					.replace(/[,;]/g, "");

				const icsContent = [
					"BEGIN:VCALENDAR",
					"VERSION:2.0",
					"PRODID:-//AIO Events//Event Registration//EN",
					"CALSCALE:GREGORIAN",
					"METHOD:PUBLISH",
					"BEGIN:VEVENT",
					"DTSTART:" + utcStart,
					"DTEND:" + utcEnd,
					"ORGANIZER;CN=" + siteName + ":" + location.origin,
					"SUMMARY:" + eventTitle.replace(/,/g, "\\,"),
					"DESCRIPTION:" +
						description.replace(/\n/g, "\\n").replace(/,/g, "\\,"),
					"STATUS:CONFIRMED",
					"UID:" + Date.now() + "@aio-events",
					"END:VEVENT",
					"END:VCALENDAR",
				].join("\r\n");

				const blob = new Blob([icsContent], {
					type: "text/calendar;charset=utf-8",
				});
				const url = URL.createObjectURL(blob);
				const link = document.createElement("a");
				link.href = url;
				link.download =
					eventTitle.replace(/[^a-z0-9]/gi, "-").toLowerCase() + ".ics";
				document.body.appendChild(link);
				link.click();
				document.body.removeChild(link);
				URL.revokeObjectURL(url);
			});
		}
	};

	// Initialize calendar buttons when success message becomes visible
	const observeSuccessMessage = () => {
		const successContainers = document.querySelectorAll(
			".aio-events-registration-success",
		);
		successContainers.forEach((container) => {
			// Init immediately if visible
			if (container.style.display !== "none") {
				initCalendarButtons();
			}

			// Observe for visibility changes
			const observer = new MutationObserver((mutations) => {
				mutations.forEach((mutation) => {
					if (
						mutation.type === "attributes" &&
						mutation.attributeName === "style"
					) {
						if (container.style.display !== "none") {
							initCalendarButtons();
						}
					}
				});
			});
			observer.observe(container, {
				attributes: true,
				attributeFilter: ["style"],
			});
		});
	};

	// Run on page load
	observeSuccessMessage();
});
