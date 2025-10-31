import os
import sys
import subprocess

# Extensiones consideradas como archivos de texto
TEXT_EXTENSIONS = {
    '.txt', '.py', '.js', '.ts', '.html', '.css', '.json', '.xml', '.yaml', '.yml',
    '.md', '.csv', '.log', '.ini', '.cfg', '.toml', '.sh', '.bat', '.ps1', '.sql',
    '.env', '.dockerfile', '.gradle', '.properties', '.go', '.rs', '.cpp', '.c',
    '.h', '.hpp', '.java', '.kt', '.rb', '.php', '.pl', '.swift', '.scala', '.r',
    '.m', '.mm', '.cs', '.fs', '.fsx', '.cljs', '.clj', '.edn', '.ex', '.exs'
}

def is_text_file(filepath):
    _, ext = os.path.splitext(filepath)
    return ext.lower() in TEXT_EXTENSIONS

def safe_read_file(filepath):
    try:
        with open(filepath, 'r', encoding='utf-8') as f:
            return f.read()
    except (UnicodeDecodeError, PermissionError, OSError) as e:
        return f"[ERROR al leer el archivo: {e}]"

def copy_to_clipboard(text):
    try:
        if sys.platform.startswith('darwin'):  # macOS
            subprocess.run(['pbcopy'], input=text.encode('utf-8'), check=True)
        elif sys.platform.startswith('win'):   # Windows
            subprocess.run(['clip'], input=text.encode('utf-8'), check=True)
        else:  # Linux (requiere xclip o xsel)
            try:
                subprocess.run(['xclip', '-selection', 'clipboard'], input=text.encode('utf-8'), check=True)
            except FileNotFoundError:
                subprocess.run(['xsel', '--clipboard', '--input'], input=text.encode('utf-8'), check=True)
        return True
    except Exception as e:
        print(f"⚠️ No se pudo copiar al portapapeles: {e}", file=sys.stderr)
        return False

def collect_files_content(root_dir='.'):
    output_lines = []
    for dirpath, _, filenames in os.walk(root_dir):
        for filename in filenames:
            filepath = os.path.join(dirpath, filename)
            if is_text_file(filepath):
                rel_path = os.path.relpath(filepath, root_dir)
                content = safe_read_file(filepath)
                output_lines.append("=" * 80)
                output_lines.append(f"Archivo: {rel_path}")
                output_lines.append("-" * 80)
                output_lines.append(content)
                output_lines.append("=" * 80)
                output_lines.append("")  # línea en blanco entre archivos
    return "\n".join(output_lines)

def main():
    target_dir = sys.argv[1] if len(sys.argv) > 1 else '.'
    if not os.path.isdir(target_dir):
        print(f"Error: '{target_dir}' no es un directorio válido.", file=sys.stderr)
        sys.exit(1)

    print("🔍 Analizando archivos de texto...")
    full_text = collect_files_content(target_dir)

    if not full_text.strip():
        print("⚠️ No se encontraron archivos de texto.")
        return

    if copy_to_clipboard(full_text):
        print("✅ Contenido copiado al portapapeles.")
    else:
        print("❌ Falló la copia al portapapeles. Mostrando salida en consola:")
        print(full_text)

if __name__ == '__main__':
    main()