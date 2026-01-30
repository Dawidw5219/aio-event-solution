/**
 * Brevo Form Translations - Monkey Patch
 * Translates checkbox labels based on domain TLD
 * Does NOT change form values - only visible text in <span> elements
 */
(() => {
	const translations = {
		// Checkbox options - EVENT_PREFERENCES
		"Currently undergoing a fertility treatment": {
			de: "Derzeit in einer Fruchtbarkeitsbehandlung",
			pl: "Obecnie w trakcie leczenia niepłodności",
			fr: "Actuellement en traitement de fertilité",
			it: "Attualmente in trattamento per la fertilità",
			es: "Actualmente en tratamiento de fertilidad",
		},
		"Looking for a fertility clinic or doctor": {
			de: "Auf der Suche nach einer Kinderwunschklinik oder einem Arzt",
			pl: "Szukam kliniki leczenia niepłodności lub lekarza",
			fr: "À la recherche d'une clinique de fertilité ou d'un médecin",
			it: "Alla ricerca di una clinica o un medico per la fertilità",
			es: "Buscando una clínica de fertilidad o un médico",
		},
		"Trying to conceive": {
			de: "Versuche schwanger zu werden",
			pl: "Próbuję zajść w ciążę",
			fr: "Essayer de concevoir",
			it: "Cercando di concepire",
			es: "Intentando concebir",
		},
		"Trying to learn more": {
			de: "Möchte mehr erfahren",
			pl: "Chcę dowiedzieć się więcej",
			fr: "Essayer d'en apprendre plus",
			it: "Cercando di saperne di più",
			es: "Intentando aprender más",
		},
		"Going abroad": {
			de: "Reise ins Ausland",
			pl: "Wyjazd za granicę",
			fr: "Partir à l'étranger",
			it: "Andare all'estero",
			es: "Ir al extranjero",
		},
		Male: {
			de: "Männlich",
			pl: "Mężczyzna",
			fr: "Homme",
			it: "Maschio",
			es: "Masculino",
		},
		Female: {
			de: "Weiblich",
			pl: "Kobieta",
			fr: "Femme",
			it: "Femmina",
			es: "Femenino",
		},
		Single: {
			de: "Alleinstehend",
			pl: "Singiel/Singielka",
			fr: "Célibataire",
			it: "Single",
			es: "Soltero/a",
		},
		"Fertility Newbie": {
			de: "Neuling im Bereich Fruchtbarkeit",
			pl: "Początkujący w temacie płodności",
			fr: "Débutant en fertilité",
			it: "Principiante nella fertilità",
			es: "Novato en fertilidad",
		},
		"Fertility veteran": {
			de: "Erfahren im Bereich Fruchtbarkeit",
			pl: "Doświadczony w temacie płodności",
			fr: "Vétéran de la fertilité",
			it: "Veterano della fertilità",
			es: "Veterano en fertilidad",
		},
		"Fertility professional": {
			de: "Fachperson im Bereich Fruchtbarkeit",
			pl: "Specjalista ds. płodności",
			fr: "Professionnel de la fertilité",
			it: "Professionista della fertilità",
			es: "Profesional de fertilidad",
		},

		// Checkbox options - TREATMENTS
		IUI: {
			de: "IUI",
			pl: "IUI",
			fr: "IUI",
			it: "IUI",
			es: "IUI",
		},
		"IVF with own eggs": {
			de: "IVF mit eigenen Eizellen",
			pl: "IVF z własnymi komórkami jajowymi",
			fr: "FIV avec ses propres ovocytes",
			it: "FIV con ovociti propri",
			es: "FIV con óvulos propios",
		},
		"Donor eggs": {
			de: "Eizellspende",
			pl: "Dawstwo komórek jajowych",
			fr: "Don d'ovocytes",
			it: "Ovociti da donatrice",
			es: "Óvulos de donante",
		},
		"Donor sperm": {
			de: "Samenspende",
			pl: "Dawstwo nasienia",
			fr: "Don de sperme",
			it: "Sperma da donatore",
			es: "Esperma de donante",
		},
		Surrogacy: {
			de: "Leihmutterschaft",
			pl: "Surogacja",
			fr: "Gestation pour autrui",
			it: "Maternità surrogata",
			es: "Gestación subrogada",
		},
		"Embryo freezing (not TTC)": {
			de: "Einfrieren von Embryonen (nicht bei Kinderwunsch)",
			pl: "Mrożenie zarodków (nie w celu poczęcia)",
			fr: "Congélation d'embryons (pas en essai bébé)",
			it: "Congelamento di embrioni (non per concepire)",
			es: "Congelación de embriones (no intentando concebir)",
		},
		"Preservation for cancer": {
			de: "Fertilitätserhalt bei Krebs",
			pl: "Zachowanie płodności przy chorobie nowotworowej",
			fr: "Préservation pour cancer",
			it: "Preservazione per cancro",
			es: "Preservación por cáncer",
		},
		None: {
			de: "Keine",
			pl: "Żadne",
			fr: "Aucun",
			it: "Nessuno",
			es: "Ninguno",
		},
	};

	function detectLanguage() {
		const hostname = window.location.hostname.toLowerCase();

		if (hostname.endsWith(".de") || hostname.includes(".de/")) return "de";
		if (hostname.endsWith(".pl") || hostname.includes(".pl/")) return "pl";
		if (hostname.endsWith(".fr") || hostname.includes(".fr/")) return "fr";
		if (hostname.endsWith(".it") || hostname.includes(".it/")) return "it";
		if (hostname.endsWith(".es") || hostname.includes(".es/")) return "es";

		const pathLang = window.location.pathname.split("/")[1];
		if (["de", "pl", "fr", "it", "es"].includes(pathLang)) return pathLang;

		return null;
	}

	function translateForm() {
		const lang = detectLanguage();
		if (!lang) return;

		const form = document.querySelector("#sib-form");
		if (!form) return;

		const checkboxLabels = form.querySelectorAll(
			".checkbox__label > span:last-child",
		);

		checkboxLabels.forEach((span) => {
			const originalText = span.textContent.trim();

			if (translations[originalText] && translations[originalText][lang]) {
				span.textContent = translations[originalText][lang];
			}
		});

		console.log(
			`[Brevo Form Translations] Applied ${lang.toUpperCase()} translations`,
		);
	}

	function init() {
		if (document.querySelector("#sib-form")) {
			translateForm();
		} else {
			const observer = new MutationObserver((mutations, obs) => {
				if (document.querySelector("#sib-form")) {
					translateForm();
					obs.disconnect();
				}
			});

			observer.observe(document.body, {
				childList: true,
				subtree: true,
			});

			setTimeout(() => observer.disconnect(), 10000);
		}
	}

	if (document.readyState === "loading") {
		document.addEventListener("DOMContentLoaded", init);
	} else {
		init();
	}
})();
