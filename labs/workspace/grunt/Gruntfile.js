const sass = require("sass");
module.exports = function (grunt) {
  grunt.initConfig({
    pkg: grunt.file.readJSON("package.json"),
    concat: {
      options: {
        sourceMap: true,
      },
      dist: {
        src: [
          // No ../ here because the Gruntfile is INSIDE the grunt folder
          "node_modules/@coreui/coreui/dist/js/coreui.bundle.min.js",
          "node_modules/simplebar/dist/simplebar.min.js",
          "node_modules/chart.js/dist/chart.umd.js",
          "node_modules/@coreui/chartjs/dist/js/coreui-chartjs.js",
          "node_modules/@coreui/utils/dist/umd/index.js",
          "node_modules/three/build/three.min.js",
          "node_modules/three/examples/js/loaders/STLLoader.js",

          // CodeMirror 5 (real code editor for the Files tab)
          "node_modules/codemirror/lib/codemirror.js",
          "node_modules/codemirror/mode/meta.js",
          "node_modules/codemirror/addon/mode/simple.js",
          "node_modules/codemirror/mode/xml/xml.js",
          "node_modules/codemirror/mode/css/css.js",
          "node_modules/codemirror/mode/javascript/javascript.js",
          "node_modules/codemirror/mode/clike/clike.js",
          "node_modules/codemirror/mode/php/php.js",
          "node_modules/codemirror/mode/python/python.js",
          "node_modules/codemirror/mode/shell/shell.js",
          "node_modules/codemirror/mode/yaml/yaml.js",
          "node_modules/codemirror/mode/markdown/markdown.js",
          "node_modules/codemirror/mode/dockerfile/dockerfile.js",
          "node_modules/codemirror/mode/htmlmixed/htmlmixed.js",

          "../js/*.js",
          "../js/quiz/*.js",
          "!../js/ui-init.js",
          "!../js/htmx-bridge.js",
          "!../js/clipboard.js",
        ],
        dest: "../../htdocs/assets/js/app.js",
      },
    },

    terser: {
      options: {
        parse: {
          ecma: 2020,
        },
        compress: false,
        mangle: false,
        format: {
          beautify: false,
        },
      },
      build: {
        files: {
          "../../htdocs/assets/js/app.min.js": ["../../htdocs/assets/js/app.js"],
        },
      },
    },

    obfuscator: {
      options: {
        banner: "",
        disableConsoleOutput: true,
        mangle: true,
        compact: true,
        simplify: true,
        domainLock: ["labs.tomweb.fun", "labsbeta.tomweb.fun"],
      },
      fast: {
        options: {
          controlFlowFlattening: false,
          debugProtection: false,
          selfDefending: false,
        },
        files: {
          "../../htdocs/assets/js/app.o.js": ["../../htdocs/assets/js/app.js"],
        },
      },
      build: {
        options: {
          debugProtection: true,
          debugProtectionInterval: true,
          controlFlowFlattening: true,
          controlFlowFlatteningThreshold: 0.25,
          selfDefending: true,
        },
        files: {
          "../../htdocs/assets/js/app.o.js": ["../../htdocs/assets/js/app.js"],
        },
      },
    },

    sass: {
      options: {
        style: "expanded",
        sourceMap: false,
        implementation: sass,
        includePaths: ["node_modules"],
      },
      dist: {
        files: {
          "../../htdocs/assets/css/app.css": "../sass/app.scss",
          "../../htdocs/assets/css/htmx-progress.css": "../sass/htmx-progress.scss",
        },
      },
    },
    copy: {
      main: {
        files: [
          {
            expand: true,
            flatten: true,
            src: ["node_modules/@coreui/icons/sprites/**"],
            dest: "../../htdocs/assets/icons/",
          },
          {
            expand: true,
            flatten: true,
            src: ["../js/ui-init.js", "../js/htmx-bridge.js", "../js/clipboard.js"],
            dest: "../../htdocs/assets/js/",
          },
        ],
      },
    },

    watch: {
      options: {
        spawn: false,
        debounceDelay: 300,
        interval: 100, // Very frequent polling for Docker responsiveness
      },
      scripts: {
        files: [
          "Gruntfile.js",
          "../js/**/*.js",
        ],
        tasks: ["concat", "secureSourceMaps", "runTerser", "obfuscator:fast"],
      },
      css: {
        files: [
          "../sass/**/*.scss",
          "../sass/**/*.sass",
        ],
        tasks: ["sass"],
      },
    },
  });

  // Log whenever sass runs to confirm detection
  grunt.registerTask("sass-log", function () {
    grunt.log.writeln(">> Sass change detected, compiling...");
  });

  // Wrap sass dist to include logging
  grunt.registerTask("sass-build", ["sass-log", "sass:dist"]);

  // Update default and watch tasks to use the new build
  grunt.config.set("watch.css.tasks", ["sass-build"]);

  grunt.loadNpmTasks("grunt-contrib-watch");
  grunt.loadNpmTasks("grunt-contrib-concat");
  grunt.loadNpmTasks("grunt-sass");
  grunt.loadNpmTasks("grunt-terser");
  grunt.loadNpmTasks("grunt-contrib-obfuscator");
  grunt.loadNpmTasks("grunt-contrib-copy");

  // Custom task to strip vendor source map comments and securely empty sourcesContent from generated maps
  grunt.registerTask(
    "secureSourceMaps",
    "Securely remove source code content from .map files",
    function () {
      const fs = require("fs");
      const jsFiles = ["../../htdocs/assets/js/app.js", "../../htdocs/assets/js/app.min.js"];
      const mapFiles = ["../../htdocs/assets/js/app.js.map", "../../htdocs/assets/js/app.min.js.map"];

      jsFiles.forEach(function (file) {
        try {
          let content = fs.readFileSync(file, "utf8");
          const lines = content.split("\n");
          // Remove vendor source maps but keep the final one generated by grunt concat (app.js.map)
          const cleanedLines = lines.map(function (line) {
            if (line.includes("sourceMappingURL=") && !line.includes("app.js.map") && !line.includes("app.min.js.map")) {
              return "";
            }
            return line;
          });
          fs.writeFileSync(file, cleanedLines.join("\n"), "utf8");
          grunt.log.ok("Cleaned vendor source maps from " + file);
        } catch (err) {
          if (err.code !== "ENOENT") grunt.log.warn("Error processing " + file + ": " + err.message);
        }
      });

      mapFiles.forEach(function (file) {
        try {
          let content = fs.readFileSync(file, "utf8");
          let mapData = JSON.parse(content);
          if (mapData.sourcesContent) {
            delete mapData.sourcesContent; // Secure it by removing actual code
            fs.writeFileSync(file, JSON.stringify(mapData), "utf8");
            grunt.log.ok("Secured source map (removed sourcesContent): " + file);
          }
        } catch (err) {
          if (err.code !== "ENOENT") grunt.log.warn("Error processing map " + file + ": " + err.message);
        }
      });
    },
  );

  grunt.registerTask("runTerser", "Run terser via CLI for proper ES6+ support", function () {
    const done = this.async();
    const { execSync } = require("child_process");
    try {
      execSync(
        "npx terser ../../htdocs/assets/js/app.js -o ../../htdocs/assets/js/app.min.js --ecma 2020 --compress --mangle",
        { cwd: __dirname, stdio: "inherit" }
      );
      grunt.log.ok("terser completed via CLI");
    } catch (e) {
      grunt.log.error("terser failed, copying unminified app.js as fallback");
      const fs = require("fs");
      fs.copyFileSync(
        "../../htdocs/assets/js/app.js",
        "../../htdocs/assets/js/app.min.js"
      );
      grunt.log.ok("Copied app.js → app.min.js (unminified fallback)");
    }
    done();
  });

  grunt.registerTask("default", [
    "copy",
    "sass-build",
    "concat",
    "secureSourceMaps",
    "runTerser",
    "watch",
  ]);
  grunt.registerTask("build", [
    "copy",
    "sass:dist",
    "concat",
    "secureSourceMaps",
    "runTerser",
    "obfuscator",
  ]);
};
