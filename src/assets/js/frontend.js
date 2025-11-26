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

	// Brevo Form Integration - let Brevo handle form, then save to our DB
	(() => {
		// Wait for form to be rendered
		const checkForm = setInterval(() => {
			const form = document.querySelector("#sib-form");

			if (form) {
				clearInterval(checkForm);

				// Auto-fill country field
				autoFillCountry(form);

				// Hide country field (auto-filled, no need to show)
				hideCountryField(form);

				// Get event ID from wrapper
				const wrapper = form.closest("#aio-events-brevo-form-wrapper");
				const eventId = wrapper ? wrapper.dataset.eventId : null;

				if (!eventId) {
					console.error("AIO Events: Event ID not found in wrapper");
					return;
				}

				const registerUrl = aioEvents.restUrl + "register";
				let lastFormData = null;
				let registrationSent = false;

				// Capture form data on submit (before Brevo processes it)
				form.addEventListener(
					"submit",
					() => {
						// Collect form data for later use - handle arrays (checkboxes with [])
						lastFormData = {};
						const formElements = form.elements;
						for (let i = 0; i < formElements.length; i++) {
							const element = formElements[i];
							if (element.name && element.name !== "email_address_check") {
								const name = element.name;

								if (element.type === "checkbox") {
									if (element.checked) {
										// Handle array checkboxes (name ends with [])
										if (name.endsWith("[]")) {
											if (!lastFormData[name]) {
												lastFormData[name] = [];
											}
											lastFormData[name].push(element.value);
										} else {
											lastFormData[name] = element.value;
										}
									}
								} else if (element.type === "radio") {
									if (element.checked) {
										lastFormData[name] = element.value;
									}
								} else if (element.value) {
									lastFormData[name] = element.value;
								}
							}
						}
						registrationSent = false;
						console.log(
							"[AIO Events] Form submitted to Brevo, captured data:",
							lastFormData,
						);
					},
					true,
				); // Use capture to run before Brevo's handler

				// Send registration to our backend
				const sendToBackend = () => {
					if (registrationSent || !lastFormData) return;
					registrationSent = true;

					console.log("[AIO Events] Sending to our backend...");

					$.ajax({
						url: registerUrl + "?event_id=" + eventId,
						type: "POST",
						contentType: "application/json",
						data: JSON.stringify({
							event_id: parseInt(eventId),
							form_data: lastFormData,
						}),
						success: (response) => {
							console.log(
								"[AIO Events] Backend registration:",
								response.success ? "OK" : "FAIL",
							);

							const $wrapper = $(wrapper);
							const $formContainer = $wrapper.find(
								".aio-events-brevo-form-container",
							);
							const $successMessage = $wrapper.find(
								".aio-events-registration-success",
							);

							if (response.success || response.already_registered) {
								$formContainer.slideUp(300);

								const email = lastFormData.EMAIL || lastFormData.email || "";
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
							}
						},
						error: (xhr) => {
							console.error(
								"[AIO Events] Backend error:",
								xhr.responseJSON?.message,
							);
							registrationSent = false; // Allow retry
						},
					});
				};

				// Check if Brevo showed success message
				const checkBrevoSuccess = () => {
					if (registrationSent || !lastFormData) return false;

					// Search entire form container for success text
					const formText = form.textContent.toLowerCase();
					if (
						formText.includes("successful") ||
						formText.includes("thank you") ||
						formText.includes("subscribed")
					) {
						console.log("[AIO Events] Brevo success detected in form text");
						return true;
					}
					return false;
				};

				// MutationObserver for DOM changes
				const observer = new MutationObserver(() => {
					if (checkBrevoSuccess()) {
						sendToBackend();
					}
				});

				observer.observe(form, {
					childList: true,
					subtree: true,
					attributes: true,
					characterData: true,
				});

				// FALLBACK: Poll every 500ms for 30 seconds after form submit
				let pollInterval = null;
				let pollCount = 0;

				form.addEventListener(
					"submit",
					() => {
						pollCount = 0;
						if (pollInterval) clearInterval(pollInterval);

						pollInterval = setInterval(() => {
							pollCount++;
							console.log("[AIO Events] Polling for success...", pollCount);

							if (checkBrevoSuccess()) {
								clearInterval(pollInterval);
								sendToBackend();
							}

							// Stop polling after 30 seconds (60 x 500ms)
							if (pollCount >= 60) {
								clearInterval(pollInterval);
								console.log("[AIO Events] Polling timeout");
							}
						}, 500);
					},
					true,
				);

				console.log(
					"[AIO Events] Brevo form integration ready (pass-through + polling)",
				);
			}
		}, 100);

		// Stop checking after 10 seconds
		setTimeout(() => {
			clearInterval(checkForm);
		}, 10000);
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
