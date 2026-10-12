# -*- coding: utf-8 -*-
"""Lit un PDF et écrit en JSON (sortie standard) ses tableaux et son texte.

Appelé par src/pdf_helpers.php. Aucune donnée n'est envoyée sur Internet : tout se passe sur ce PC.
Usage : python pdf_tables.py <fichier.pdf>
Sortie : {"ok": true, "pages": N, "texte": "...", "tableaux": [{"page": 1, "lignes": [[cellule, ...], ...]}, ...]}
         ou {"ok": false, "erreur": "..."}
"""
import json
import os
import sys
import warnings

warnings.filterwarnings("ignore")

# PyMuPDF écrit parfois des conseils sur la sortie standard : on la détourne pendant le travail
# pour que seule la réponse JSON y soit écrite à la fin.
_sortie = sys.stdout
try:
    _sortie.reconfigure(encoding="utf-8")
except Exception:  # noqa: BLE001
    pass
sys.stdout = sys.stderr


def emettre(obj):
    sys.stdout = _sortie
    print(json.dumps(obj, ensure_ascii=False))

# Copie locale de PyMuPDF embarquée dans le projet (python_libs/), sinon installation du système.
_ici = os.path.dirname(os.path.abspath(__file__))
_libs = os.path.join(os.path.dirname(_ici), "python_libs")
if os.path.isdir(_libs):
    sys.path.insert(0, _libs)

try:
    import pymupdf
except Exception as e:  # noqa: BLE001
    emettre({"ok": False, "erreur": "PyMuPDF introuvable (" + str(e)[:80] + "). Installer : pip install pymupdf"})
    sys.exit(0)


def main():
    if len(sys.argv) < 2:
        emettre({"ok": False, "erreur": "aucun fichier"})
        return
    try:
        doc = pymupdf.open(sys.argv[1])
    except Exception as e:  # noqa: BLE001
        emettre({"ok": False, "erreur": "PDF illisible ou protégé : " + str(e)[:100]})
        return
    if doc.needs_pass:
        emettre({"ok": False, "erreur": "PDF protégé par un mot de passe."})
        return

    texte_pages = []
    tableaux = []
    for i, page in enumerate(doc):
        texte_pages.append(page.get_text())
        try:
            trouves = page.find_tables().tables
        except Exception:  # noqa: BLE001
            trouves = []
        for t in trouves:
            lignes = [[(c or "").replace("\r", " ").replace("\n", " ").strip() for c in ligne] for ligne in t.extract()]
            if lignes:
                tableaux.append({"page": i + 1, "lignes": lignes})

    texte = "\n".join(texte_pages)
    emettre({"ok": True, "pages": len(doc), "caracteres": len(texte.strip()), "texte": texte[:6000], "tableaux": tableaux})


if __name__ == "__main__":
    main()
