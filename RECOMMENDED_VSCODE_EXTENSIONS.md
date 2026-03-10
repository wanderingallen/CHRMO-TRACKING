# Recommended VS Code Extensions for CHRMO Document Tracking System

This document lists recommended VS Code extensions for developing the CHRMO Document Tracking System, which includes:
- **Flutter/Dart** mobile application
- **PHP** backend web application
- **Python** AI Document Processor microservice
- **MySQL/SQLite** database
- **HTML/CSS/JavaScript** frontend

---

## 🎯 Essential Extensions (Must-Have)

### Flutter & Dart Development
- **Dart** (`dart-code.dart`)
  - Official Dart language support with syntax highlighting, IntelliSense, and debugging
- **Flutter** (`dart-code.flutter`)
  - Official Flutter extension with hot reload, widget inspector, and device management
- **Flutter Widget Snippets** (`alexisvt.flutter-snippets`)
  - Common Flutter widget snippets to speed up development

### PHP Development
- **PHP Intelephense** (`bmewburn.vscode-intelephense-client`)
  - Advanced PHP language support with autocomplete, go-to-definition, and code intelligence
- **PHP Debug** (`xdebug.php-debug`)
  - Xdebug integration for debugging PHP applications
- **PHP DocBlocker** (`neilbrayfield.php-docblocker`)
  - Auto-generates PHPDoc comments for functions and classes

### Python Development
- **Python** (`ms-python.python`)
  - Official Python extension with IntelliSense, linting, debugging, and Jupyter support
- **Pylance** (`ms-python.vscode-pylance`)
  - Fast, feature-rich Python language server
- **Python Docstring Generator** (`njpwerner.autodocstring`)
  - Generates docstrings automatically

---

## 🔧 Development Tools

### Database Management
- **MySQL** (`cweijan.vscode-mysql-client2`)
  - MySQL database client with query execution and table management
- **SQLTools** (`mtxr.sqltools`)
  - Database management with support for MySQL, SQLite, and more
- **SQLTools MySQL/MariaDB** (`mtxr.sqltools-driver-mysql`)
  - MySQL driver for SQLTools

### Version Control
- **GitLens** (`eamodio.gitlens`)
  - Enhanced Git capabilities with blame annotations, file history, and more
- **Git Graph** (`mhutchie.git-graph`)
  - Visualize Git repository history

### Code Quality & Formatting
- **Prettier** (`esbenp.prettier-vscode`)
  - Code formatter for JavaScript, TypeScript, HTML, CSS, JSON, and more
- **ESLint** (`dbaeumer.vscode-eslint`)
  - JavaScript/TypeScript linting
- **PHP CS Fixer** (`junstyle.php-cs-fixer`)
  - PHP code formatter following PSR standards
- **Dart Code Metrics** (`dart-code.dart-code-metrics`)
  - Analyzes Dart code quality and complexity

---

## 🎨 Frontend Development

### HTML/CSS/JavaScript
- **HTML CSS Support** (`ecmel.vscode-html-css`)
  - CSS class and ID completion for HTML
- **Tailwind CSS IntelliSense** (`bradlc.vscode-tailwindcss`)
  - Autocomplete, syntax highlighting, and linting for Tailwind CSS
- **JavaScript (ES6) code snippets** (`xabikos.javascriptsnippets`)
  - ES6 syntax snippets
- **Auto Rename Tag** (`formulahendry.auto-rename-tag`)
  - Automatically renames paired HTML/XML tags

### Live Preview
- **Live Server** (`ritwickdey.liveserver`)
  - Launch a development local server with live reload
- **Live Preview** (`ms-vscode.live-server`)
  - Microsoft's live preview extension

---

## 🐛 Debugging & Testing

### Debugging
- **Error Lens** (`usernamehw.errorlens`)
  - Highlights errors and warnings inline in the editor
- **Debugger for Chrome** (`msjsdiag.debugger-for-chrome`)
  - Debug JavaScript in Chrome from VS Code

### Testing
- **Flutter Test Explorer** (`dantup.flutter-test-explorer`)
  - Test explorer for Flutter/Dart tests
- **Python Test Explorer** (`littlefoxteam.vscode-python-test-adapter`)
  - Test explorer for Python tests

---

## 📝 Productivity & Code Navigation

### Code Navigation
- **Path Intellisense** (`christian-kohler.path-intellisense`)
  - Autocompletes file paths
- **Bracket Pair Colorizer 2** (`coenraads.bracket-pair-colorizer-2`)
  - Colorizes matching brackets (Note: Built-in in VS Code 1.60+)
- **Indent Rainbow** (`oderwat.indent-rainbow`)
  - Colorizes indentation for better readability

### Snippets & Templates
- **PHP Snippets** (`DEVSENSE.phptools-vscode`)
  - PHP code snippets
- **Dart Data Class Generator** (`kirkslabs.dart-data-class-generator`)
  - Generates Dart data classes

### Search & Replace
- **Search node_modules** (`jasonnutter.search-node-modules`)
  - Search within node_modules
- **Advanced New File** (`patbenatar.advanced-new-file`)
  - Create new files with path completion

---

## 🔒 Security & Best Practices

### Security
- **SonarLint** (`sonarsource.sonarlint`)
  - Detects bugs and security vulnerabilities in code
- **PHP Security Checker** (`sensiolabs.vscode-php-security-checker`)
  - Checks PHP dependencies for known security vulnerabilities

### Code Analysis
- **Code Spell Checker** (`streetsidesoftware.code-spell-checker`)
  - Spelling checker for code
- **Todo Tree** (`gruntfuggly.todo-tree`)
  - Highlights TODO, FIXME, and other comment tags

---

## 🎯 Project-Specific Extensions

### Composer (PHP Dependency Manager)
- **Composer** (`ikappas.composer`)
  - Composer support for PHP projects

### Firebase (Used in Flutter App)
- **Firebase** (`toba.vsfire`)
  - Firebase tools integration (optional, if using Firebase CLI)

### Docker (If containerizing)
- **Docker** (`ms-azuretools.vscode-docker`)
  - Docker support for containerized development

---

## 📦 Package Managers

### npm/yarn (If using Node.js tools)
- **npm Intellisense** (`christian-kohler.npm-intellisense`)
  - Autocompletes npm modules in import statements

---

## 🎨 UI & Theme (Optional)

### Themes
- **Material Icon Theme** (`pkief.material-icon-theme`)
  - Material Design icons for files and folders
- **One Dark Pro** (`zhuangtongfa.material-theme`)
  - Popular dark theme

### UI Enhancements
- **Peacock** (`johnpapa.vscode-peacock`)
  - Subtly change workspace color to distinguish projects

---

## 📋 Quick Install Script

You can install all essential extensions at once using VS Code's command palette:

1. Open Command Palette (`Ctrl+Shift+P` / `Cmd+Shift+P`)
2. Type: `Extensions: Show Recommended Extensions`
3. Or install via command line:

```bash
# Flutter & Dart
code --install-extension dart-code.dart
code --install-extension dart-code.flutter

# PHP
code --install-extension bmewburn.vscode-intelephense-client
code --install-extension xdebug.php-debug

# Python
code --install-extension ms-python.python
code --install-extension ms-python.vscode-pylance

# Database
code --install-extension cweijan.vscode-mysql-client2
code --install-extension mtxr.sqltools

# Code Quality
code --install-extension esbenp.prettier-vscode
code --install-extension dbaeumer.vscode-eslint

# Tailwind CSS
code --install-extension bradlc.vscode-tailwindcss

# Git
code --install-extension eamodio.gitlens
```

---

## ⚙️ Recommended VS Code Settings

Add these to your `.vscode/settings.json`:

```json
{
  // PHP
  "php.suggest.basic": false,
  "intelephense.files.maxSize": 5000000,
  
  // Python
  "python.linting.enabled": true,
  "python.linting.pylintEnabled": true,
  "python.formatting.provider": "black",
  
  // Dart/Flutter
  "dart.lineLength": 80,
  "dart.enableSdkFormatter": true,
  
  // Editor
  "editor.formatOnSave": true,
  "editor.codeActionsOnSave": {
    "source.fixAll": true
  },
  
  // Files
  "files.exclude": {
    "**/.git": true,
    "**/.DS_Store": true,
    "**/node_modules": true,
    "**/build": true,
    "**/vendor": false
  },
  
  // Tailwind CSS
  "tailwindCSS.experimental.classRegex": [
    ["class:\\s*?[\"'`]([^\"'`]*).*?[\"'`]", "([^\\s]*)"]
  ]
}
```

---

## 📝 Notes

- **Performance**: Some extensions may slow down VS Code. Disable unused extensions.
- **Conflicts**: Some extensions may conflict (e.g., multiple PHP extensions). Choose one primary extension per language.
- **Updates**: Keep extensions updated for latest features and security fixes.
- **Workspace**: Consider using workspace-specific extensions for this project.

---

## 🔗 Useful Resources

- [VS Code Marketplace](https://marketplace.visualstudio.com/vscode)
- [Flutter Extension Documentation](https://dartcode.org/)
- [PHP Intelephense Documentation](https://intelephense.com/)
- [Python Extension Documentation](https://code.visualstudio.com/docs/python/python-tutorial)

---

**Last Updated**: 2025-01-09
**Project**: CHRMO Document Tracking System





