require("dotenv").config({ path: ".env.local", override: true });
require("dotenv").config();
const fs = require("node:fs");
const path = require("node:path");

module.exports = (grunt) => {
	grunt.loadNpmTasks("grunt-contrib-compress");
	grunt.loadNpmTasks("grunt-contrib-watch");
	grunt.loadNpmTasks("grunt-contrib-cssmin");
	grunt.loadNpmTasks("grunt-contrib-uglify");
	grunt.loadNpmTasks("grunt-ftp-push");
	grunt.loadNpmTasks("grunt-sync");
	grunt.loadNpmTasks("grunt-exec");

	// Optional: i18n support (loaded if grunt-wp-i18n is installed)
	try { grunt.loadNpmTasks("grunt-wp-i18n"); } catch (e) {}

	// ─── Config (auto-detected) ──────────────────────────────────────
	// srcDir: prefer src/ if it exists with a plugin PHP file, else use root.
	// Override with GRUNT_SRCDIR env var if needed.
	function findMainPhp(dir) {
		if (!fs.existsSync(dir)) return null;
		return fs.readdirSync(dir).find((f) => {
			if (!f.endsWith(".php")) return false;
			const content = fs.readFileSync(path.join(dir, f), "utf8");
			return /Plugin Name:/i.test(content);
		}) || null;
	}

	let srcDir = process.env.GRUNT_SRCDIR || (findMainPhp("src") ? "src" : ".");
	let mainPhp = findMainPhp(srcDir);
	if (!mainPhp) grunt.fail.fatal("No main plugin PHP file found in " + srcDir + "/");

	// minify: skip if there's no assets/ folder to minify (Vite/Webpack handle it).
	const minifyEnabled = process.env.GRUNT_MINIFY !== "false" && fs.existsSync(path.join(srcDir, "assets"));
	const pluginName = mainPhp.replace(".php", "");
	const slug = pluginName;

	function getVersion() {
		const content = fs.readFileSync(path.join(srcDir, mainPhp), "utf8");
		const m = content.match(/Version:\s*([\d.]+)/);
		return m ? m[1] : "0.0.0";
	}

	function findPhpTool(name) {
		const candidates = [
			`./vendor/bin/${name}`,
			path.join(process.env.HOME || "", ".composer/vendor/bin", name),
		];
		for (const c of candidates) {
			if (fs.existsSync(c)) return c;
		}
		return name; // fall back to PATH
	}

	function getWpPath() {
		return process.env.LOCAL_WP_PLUGINS_FOLDER_PATH || "";
	}

	function requireWpPath() {
		const p = getWpPath();
		if (!p) {
			grunt.log.error(
				"Set LOCAL_WP_PLUGINS_FOLDER_PATH in .env"
			);
			return false;
		}
		return p;
	}

	// ─── Grunt config ────────────────────────────────────────────────
	grunt.initConfig({
		makepot: {
			target: {
				options: {
					type: "wp-plugin",
					potFilename: pluginName + ".pot",
					potHeaders: { poedit: true, "x-poedit-keywordslist": true },
					updateTimestamp: true,
					cwd: srcDir,
					exclude: ["vendor/**", "node_modules/**"],
					domainPath: "/languages",
				},
			},
		},

		uglify: {
			target: {
				files: [
					{
						expand: true,
						cwd: srcDir + "/assets/",
						src: [
							"admin/**/*.js",
							"!admin/**/*.min.js",
							"public/**/*.js",
							"!public/**/*.min.js",
						],
						dest: srcDir + "/assets/",
						ext: ".min.js",
					},
				],
			},
		},

		cssmin: {
			target: {
				files: [
					{
						expand: true,
						cwd: srcDir + "/assets/",
						src: [
							"admin/**/*.css",
							"!admin/**/*.min.css",
							"public/**/*.css",
							"!public/**/*.min.css",
						],
						dest: srcDir + "/assets/",
						ext: ".min.css",
					},
				],
			},
		},

		compress: {
			dist: {
				options: {
					archive: function () {
						return "builds/" + slug + "-" + getVersion() + ".zip";
					},
					mode: "zip",
				},
				files: [
					{
						expand: true,
						cwd: srcDir + "/",
						src: ["**/*"],
						dest: pluginName + "/",
						// Filter runs against the absolute file path; we strip cwd and
						// match the resulting relative path against an exclusion list.
						// This is more reliable than glob negation patterns when the
						// repository contains node_modules at the same level as plugin code.
						filter: function (filepath) {
							const relRaw = path.relative(path.resolve(srcDir), filepath);
							const rel = relRaw.split(path.sep).join("/");
							const segments = rel.split("/");

							// Always-excluded directory names (any depth).
							const excludedDirs = new Set([
								".svn", ".git", "node_modules", ".DS_Store",
							]);
							for (const seg of segments) {
								if (excludedDirs.has(seg)) return false;
							}

							// Always-excluded patterns.
							const alwaysExcludedFiles = new Set([
								".gitignore", ".gitattributes", ".distignore",
								".htaccess", ".DS_Store",
							]);
							const basename = segments[segments.length - 1];
							if (alwaysExcludedFiles.has(basename)) return false;
							if (/\.(map|scss|sass|less|phar)$/.test(basename)) return false;

							// Root-mode extras: when the plugin lives alongside build tooling.
							if (srcDir === ".") {
								const rootExcludedDirs = new Set([
									"src", "cli", "scripts", "widgets", "builds",
								]);
								if (segments.length > 0 && rootExcludedDirs.has(segments[0])) return false;

								const rootExcludedFiles = new Set([
									"package.json", "package-lock.json", "pnpm-lock.yaml",
									"yarn.lock", "tsconfig.json", "vite.config.ts",
									"gruntfile.cjs", "gruntfile.js", "Gruntfile.cjs", "Gruntfile.js",
									"phpcs.xml", "phpcs.xml.dist", "README.md", "todo.md",
									".wp-path", "index.html", "REVIEW.md", ".env", ".env.local",
									".env.example",
								]);
								if (segments.length === 1 && rootExcludedFiles.has(basename)) return false;
								if (segments.length === 1 && /^tsconfig\..*\.json$/.test(basename)) return false;
								if (segments.length === 1 && /^vite\..*\.config\.ts$/.test(basename)) return false;
								if (segments.length === 1 && /^tailwind\.config\./.test(basename)) return false;
							}

							return true;
						},
					},
					],
			},
		},

		sync: {
			main: {
				files: [
					{
						cwd: srcDir,
						src: [
							"**",
							"!**/.DS_Store",
							"!**/.svn/**",
							"!**/node_modules/**",
							"!.git/**",
							"!.turbo/**",
							"!.claude/**",
							"!builds/**",
							"!src/**",
							"!cli/**",
							"!scripts/**",
							"!widgets/**",
							"!package.json",
							"!package-lock.json",
							"!pnpm-lock.yaml",
							"!yarn.lock",
							"!tsconfig.json",
							"!tsconfig.*.json",
							"!vite.config.ts",
							"!vite.*.config.ts",
							"!tailwind.config.*",
							"!gruntfile.cjs",
							"!gruntfile.js",
							"!Gruntfile.cjs",
							"!Gruntfile.js",
							"!phpcs.xml",
							"!phpcs.xml.dist",
							"!README.md",
							"!todo.md",
							"!REVIEW.md",
							"!index.html",
							"!.env",
							"!.env.*",
							"!.gitignore",
							"!.gitattributes",
							"!.distignore",
							"!.htaccess",
							"!biome.json",
							"!.wp-path",
							"!**/*.map",
							"!**/*.scss",
							"!**/*.sass",
							"!**/*.less",
							"!**/*.phar",
						],
						dest: (getWpPath() || "/tmp") + "/" + pluginName,
					},
				],
				updateAndDelete: true,
				compareUsing: "mtime",
				verbose: true,
				pretend: false,
			},
		},

		watch: {
			dev: {
				files: [srcDir + "/**/*"],
				tasks: ["sync:main"],
				options: {
					spawn: false,
					livereload: false,
					interrupt: true,
					event: ["all"],
				},
			},
			dev_build: {
				files: [srcDir + "/**/*"],
				tasks: ["uglify", "cssmin", "sync:main"],
				options: {
					spawn: false,
					livereload: false,
					interrupt: true,
				},
			},
			ftp: {
				files: [srcDir + "/**/*"],
				tasks: ["uglify", "cssmin", "ftp_push:updates"],
				options: { spawn: false },
			},
		},

		ftp_push: {
			full: {
				options: {
					port: process.env.FTP_PORT || 21,
					host: process.env.FTP_HOST,
					username: process.env.FTP_USERNAME,
					password: process.env.FTP_PASSWORD,
					dest:
						process.env.FTP_WP_PLUGINS_FOLDER_PATH +
						"/" +
						pluginName,
					incrementalUpdates: false,
				},
				files: [{ expand: true, cwd: srcDir, src: ["**", "!**/.svn/**"] }],
			},
			updates: {
				options: {
					port: process.env.FTP_PORT || 21,
					host: process.env.FTP_HOST,
					username: process.env.FTP_USERNAME,
					password: process.env.FTP_PASSWORD,
					dest:
						process.env.FTP_WP_PLUGINS_FOLDER_PATH +
						"/" +
						pluginName,
					incrementalUpdates: true,
				},
				files: [{ expand: true, cwd: srcDir, src: ["**", "!**/.svn/**"] }],
			},
		},

		exec: {
			phpcs: {
				cmd: function () {
					return `${findPhpTool("phpcs")} -d memory_limit=512M --standard=phpcs.xml --report=full`;
				},
			},
			phpcbf: {
				cmd: function () {
					return `${findPhpTool("phpcbf")} -d memory_limit=512M --standard=phpcs.xml`;
				},
				exitCode: [0, 1],
			},

			// ─── SVN deploy tasks ────────────────────────────────────
			svn_check: {
				cmd: 'which svn || (echo "ERROR: svn not found. Install with: brew install subversion" && exit 1)',
			},
			svn_add_remove: {
				cmd: function () {
					// Run svn add/remove in both src (trunk) and assets
					const dirs = [srcDir, "assets"].filter((d) => fs.existsSync(path.join(d, ".svn")));
					return dirs.map((d) => [
						`cd "${d}"`,
						`svn status | grep '^?' | awk '{print $2}' | xargs -I{} svn add "{}" || true`,
						`svn status | grep '^!' | awk '{print $2}' | xargs -I{} svn delete "{}" || true`,
						`cd ..`,
					].join(" && ")).join(" && ");
				},
			},
			svn_commit: {
				cmd: function () {
					const version = grunt.config.get("wporg.version");
					const svnUrl = grunt.config.get("wporg.svnUrl");
					const auth = grunt.config.get("wporg.auth");
					// Commit both src (trunk) and assets in one changelist
					const dirs = [srcDir, "assets"].filter((d) => fs.existsSync(path.join(d, ".svn")));
					const commitCmd = dirs.map((d) => `cd "${d}" && svn commit -m "Release ${version}" ${auth} && cd ..`).join(" && ");
					// Remove all old tags, then create new tag from trunk (only latest version available)
					const deleteOldTags = `svn list "${svnUrl}/tags" ${auth} 2>/dev/null | sed 's|/||' | while read tag; do svn delete "${svnUrl}/tags/$tag" -m "Remove old tag $tag" ${auth}; done || true`;
					const createTag = `svn copy "${svnUrl}/trunk" "${svnUrl}/tags/${version}" -m "Tag ${version}" ${auth}`;
					return commitCmd + " && " + deleteOldTags + " && " + createTag;
				},
			},
		},
	});

	// ─── Tasks ───────────────────────────────────────────────────────

	// build  → minify (if enabled) + zip. Never syncs to WP (use `dev` for that).
	grunt.registerTask("build", function () {
		const v = getVersion();
		grunt.log.writeln(slug + " v" + v + " → builds/" + slug + "-" + v + ".zip");

		const tasks = [];
		if (minifyEnabled) {
			tasks.push("uglify", "cssmin");
		} else {
			grunt.log.writeln("(minify skipped — handled externally or no assets/ folder)");
		}
		tasks.push("compress");

		grunt.task.run(tasks);
	});

	// dev  → minify (if enabled) + sync + watch (rebuilds on change)
	grunt.registerTask("dev", function () {
		if (!requireWpPath()) return false;
		grunt.log.writeln("Dev: " + getWpPath() + "/" + pluginName);
		const tasks = [];
		if (minifyEnabled) tasks.push("uglify", "cssmin");
		tasks.push("sync:main", minifyEnabled ? "watch:dev_build" : "watch:dev");
		grunt.task.run(tasks);
	});

	// dev:ftp  → FTP full push + watch
	grunt.registerTask("dev:ftp", function () {
		if (!process.env.FTP_HOST) {
			grunt.log.error("FTP not configured in .env");
			return false;
		}
		grunt.log.writeln("Dev+FTP: " + process.env.FTP_HOST);
		grunt.task.run(["uglify", "cssmin", "ftp_push:full", "watch:ftp"]);
	});

	// deploy  → minify + FTP full push (one-shot)
	grunt.registerTask("deploy", function () {
		if (!process.env.FTP_HOST) {
			grunt.log.error("FTP not configured in .env");
			return false;
		}
		grunt.log.writeln("Deploy via FTP to " + process.env.FTP_HOST);
		grunt.task.run(["uglify", "cssmin", "ftp_push:full"]);
	});

	// assets  → generate icon + banner sizes from source images in assets/
	grunt.registerTask("assets", "Generate WP.org plugin assets (icon + banner)", function () {
		const assetsDir = "assets";
		if (!fs.existsSync(assetsDir)) {
			fs.mkdirSync(assetsDir);
		}

		const { execSync } = require("child_process");
		const run = (cmd) => execSync(cmd, { stdio: "inherit" });

		// Icons — GIF has priority over PNG
		const iconGif = path.join(assetsDir, "icon.gif");
		const iconPng = path.join(assetsDir, "icon.png");
		if (fs.existsSync(iconGif)) {
			grunt.log.writeln("→ Generating icons from icon.gif (priority over PNG)");
			run(`magick "${iconGif}" -coalesce -resize 256x256 -layers Optimize "${assetsDir}/icon-256x256.gif"`);
			run(`magick "${iconGif}" -coalesce -resize 128x128 -layers Optimize "${assetsDir}/icon-128x128.gif"`);
			grunt.log.ok("icon-256x256.gif + icon-128x128.gif");
		} else if (fs.existsSync(iconPng)) {
			grunt.log.writeln("→ Generating icons from icon.png");
			run(`magick "${iconPng}" -resize 256x256 -strip "${assetsDir}/icon-256x256.png"`);
			run(`magick "${iconPng}" -resize 128x128 -strip "${assetsDir}/icon-128x128.png"`);
			grunt.log.ok("icon-256x256.png + icon-128x128.png");
		} else {
			grunt.log.warn("assets/icon.{gif,png} not found — skipping icons");
		}

		// Banners — GIF has priority over PNG
		const bannerGif = path.join(assetsDir, "banner.gif");
		const bannerPng = path.join(assetsDir, "banner.png");
		if (fs.existsSync(bannerGif)) {
			grunt.log.writeln("→ Generating banners from banner.gif (priority over PNG)");
			run(`magick "${bannerGif}" -coalesce -resize 772x250 -layers Optimize "${assetsDir}/banner-772x250.gif"`);
			grunt.log.ok("banner-772x250.gif");
		} else if (fs.existsSync(bannerPng)) {
			grunt.log.writeln("→ Generating banners from banner.png");
			run(`magick "${bannerPng}" -resize 1544x500 -strip "${assetsDir}/banner-1544x500.png"`);
			run(`magick "${bannerPng}" -resize 772x250 -strip "${assetsDir}/banner-772x250.png"`);
			grunt.log.ok("banner-1544x500.png + banner-772x250.png");
		} else {
			grunt.log.warn("assets/banner.{gif,png} not found — skipping banners");
		}
	});

	// lint
	grunt.registerTask("lint", ["exec:phpcbf", "exec:phpcs"]);

	// deploy:wp  → lint + build + SVN publish to wordpress.org
	grunt.registerTask("deploy:wp", "Deploy plugin to WordPress.org SVN", function () {
		const version = getVersion();
		const svnUrl = "https://plugins.svn.wordpress.org/" + pluginName;
		const auth = `--username "${process.env.SVN_USERNAME || ""}" --password "${process.env.SVN_PASSWORD || ""}" --non-interactive --trust-server-cert`;

		// Pre-flight checks
		if (!process.env.SVN_USERNAME || !process.env.SVN_PASSWORD) {
			grunt.fail.fatal("SVN_USERNAME and SVN_PASSWORD must be set in .env");
		}

		if (version === "0.0.0") {
			grunt.fail.fatal("Could not parse version from " + mainPhp);
		}

		const { execSync: execGit } = require("child_process");
		const gitStatus = execGit("git status --porcelain -- .", { encoding: "utf8" });
		if (gitStatus.trim()) {
			const commitMsg = pluginName + "-" + version;
			grunt.log.writeln("→ Auto-committing: " + commitMsg);
			execGit("git add -A .", { stdio: "inherit" });
			execGit(`git commit -m "${commitMsg}"`, { stdio: "inherit" });
		}

		// Auto-sync Stable tag in readme.txt to match PHP header version
		const readmePath = path.join(srcDir, "readme.txt");
		const readmeTxt = fs.readFileSync(readmePath, "utf8");
		const stableTag = readmeTxt.match(/Stable tag:\s*([\d.]+)/);
		if (!stableTag || stableTag[1] !== version) {
			const updated = readmeTxt.replace(/Stable tag:\s*[\d.]+/, "Stable tag: " + version);
			fs.writeFileSync(readmePath, updated);
			grunt.log.ok("Auto-updated readme.txt Stable tag → " + version);
		}

		grunt.log.writeln("");
		grunt.log.writeln("  Plugin:  " + pluginName);
		grunt.log.writeln("  Version: " + version);
		grunt.log.writeln("  SVN URL: " + svnUrl);
		grunt.log.writeln("");

		grunt.config.set("wporg.svnUrl", svnUrl);
		grunt.config.set("wporg.version", version);
		grunt.config.set("wporg.auth", auth);

		grunt.task.run([
			"exec:svn_check",
			"wporg_prepare",
			"exec:svn_add_remove",
			"exec:svn_commit",
		]);
	});

	// Run lint + build + svn checkout in parallel
	grunt.registerTask("wporg_prepare", "Lint, build and SVN checkout in parallel", function () {
		const done = this.async();
		const { execSync, spawn } = require("child_process");

		const svnUrl = grunt.config.get("wporg.svnUrl");
		const auth = grunt.config.get("wporg.auth");

		// SVN checkout/update directly into src/ (trunk) and assets/
		const svnCmds = [];
		if (fs.existsSync(path.join(srcDir, ".svn"))) {
			grunt.log.writeln("→ SVN update (cached)");
			svnCmds.push(`svn update "${srcDir}" ${auth}`);
			if (fs.existsSync(path.join("assets", ".svn"))) {
				svnCmds.push(`svn update "assets" ${auth}`);
			}
		} else {
			grunt.log.writeln("→ SVN checkout (first time)");
			svnCmds.push(`svn checkout --force "${svnUrl}/trunk" "${srcDir}" ${auth}`);
			svnCmds.push(`svn checkout --force "${svnUrl}/assets" "assets" ${auth}`);
		}

		const svnProc = spawn("sh", ["-c", svnCmds.join(" && ")], { stdio: "inherit" });

		// Run lint + build synchronously while SVN works in background
		try {
			grunt.log.writeln("→ Lint + Build (parallel with SVN)");
			execSync(`${findPhpTool("phpcbf")} -d memory_limit=512M --standard=phpcs.xml || true`, { stdio: "inherit" });
			execSync(`${findPhpTool("phpcs")} -d memory_limit=512M --standard=phpcs.xml --report=full`, { stdio: "inherit" });

			grunt.log.writeln("→ Minify JS + CSS");
			execSync("npx grunt uglify cssmin", { stdio: "inherit" });

			// Generate icons/banners — GIF has priority over PNG
			const assetsDir = "assets";
			const iconGif = path.join(assetsDir, "icon.gif");
			const iconPng = path.join(assetsDir, "icon.png");
			const bannerGif = path.join(assetsDir, "banner.gif");
			const bannerPng = path.join(assetsDir, "banner.png");
			if (fs.existsSync(iconGif)) {
				execSync(`magick "${iconGif}" -coalesce -resize 256x256 -layers Optimize "${assetsDir}/icon-256x256.gif"`, { stdio: "inherit" });
				execSync(`magick "${iconGif}" -coalesce -resize 128x128 -layers Optimize "${assetsDir}/icon-128x128.gif"`, { stdio: "inherit" });
				grunt.log.ok("icons generated (GIF)");
			} else if (fs.existsSync(iconPng)) {
				execSync(`magick "${iconPng}" -resize 256x256 -strip "${assetsDir}/icon-256x256.png"`, { stdio: "inherit" });
				execSync(`magick "${iconPng}" -resize 128x128 -strip "${assetsDir}/icon-128x128.png"`, { stdio: "inherit" });
				grunt.log.ok("icons generated (PNG)");
			}
			if (fs.existsSync(bannerGif)) {
				execSync(`magick "${bannerGif}" -coalesce -resize 772x250 -layers Optimize "${assetsDir}/banner-772x250.gif"`, { stdio: "inherit" });
				grunt.log.ok("banner generated (GIF)");
			} else if (fs.existsSync(bannerPng)) {
				execSync(`magick "${bannerPng}" -resize 1544x500 -strip "${assetsDir}/banner-1544x500.png"`, { stdio: "inherit" });
				execSync(`magick "${bannerPng}" -resize 772x250 -strip "${assetsDir}/banner-772x250.png"`, { stdio: "inherit" });
				grunt.log.ok("banners generated (PNG)");
			}
		} catch (e) {
			svnProc.kill();
			grunt.fail.fatal("Lint/build failed: " + e.message);
		}

		// Wait for SVN to finish
		svnProc.on("close", function (code) {
			if (code !== 0) {
				grunt.fail.fatal("SVN checkout/update failed with code " + code);
			}
			grunt.log.ok("All ready — SVN + lint + build done");
			done();
		});
	});

grunt.registerTask("default", ["build"]);
};
