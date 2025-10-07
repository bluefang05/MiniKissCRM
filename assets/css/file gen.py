#!/usr/bin/env python3
import os

# Base CSS files
base_files = [
    "_reset.css",
    "_variables.css",
    "_typography.css",
    "_buttons.css",
    "_forms.css",
    "_tables.css"
]

# Component-level CSS files
component_files = [
    "_header.css",
    "_sidebar.css",
    "_footer.css"
]

# PHP folder to CSS mapping
php_to_css_map = {
    "admin": [
        "dashboard.php", "users.php", "agent_detail.php", "all_metrics.php",
        "my_metrics.php", "owner_dashboard.php", "documents.php",
        "document_delete.php", "document_edit.php", "export.php"
    ],
    "leads": [
        "add.php", "edit.php", "list.php", "view.php",
        "release.php", "save.php", "import.php"
    ],
    "calls": [
        "add.php", "list.php", "my_interactions.php", "view.php"
    ],
    "auth": [
        "login.php", "logout.php"
    ],
    "documents": [
        "upload.php"
    ]
}

def create_folder_and_files(folder_path, php_files):
    os.makedirs(folder_path, exist_ok=True)
    css_files = [f.replace(".php", ".css") for f in php_files]
    for css in css_files:
        open(os.path.join(folder_path, css), 'a').close()
    print(f"Created {len(css_files)} files in {folder_path}")

def create_app_css(css_folder):
    app_css = os.path.join(css_folder, "app.css")
    with open(app_css, "w", encoding="utf-8") as f:
        f.write("/* Main CSS - auto-generated; do not edit directly */\n\n")
        # Base imports
        for bf in base_files:
            f.write(f"@import 'base/{bf}';\n")
        f.write("\n")
        # Component imports
        for cf in component_files:
            f.write(f"@import 'components/{cf}';\n")
        f.write("\n")
        # Section imports
        for section, files in php_to_css_map.items():
            for php in files:
                css_name = php.replace(".php", ".css")
                f.write(f"@import '{section}/{css_name}';\n")
    print(f"Generated {app_css}")

def main():
    # Run from project root (where assets/ lives)
    project_root = os.getcwd()
    css_folder = os.path.join(project_root, "assets", "css")
    os.makedirs(css_folder, exist_ok=True)

    # Create base/
    base_dir = os.path.join(css_folder, "base")
    os.makedirs(base_dir, exist_ok=True)
    for bf in base_files:
        open(os.path.join(base_dir, bf), 'a').close()
    print(f"Created base CSS files in {base_dir}")

    # Create components/
    comp_dir = os.path.join(css_folder, "components")
    os.makedirs(comp_dir, exist_ok=True)
    for cf in component_files:
        open(os.path.join(comp_dir, cf), 'a').close()
    print(f"Created component CSS files in {comp_dir}")

    # Create per-section CSS folders and files
    for section, files in php_to_css_map.items():
        section_dir = os.path.join(css_folder, section)
        create_folder_and_files(section_dir, files)

    # Generate top-level app.css
    create_app_css(css_folder)

    print("CSS structure created successfully!")

if __name__ == "__main__":
    main()
