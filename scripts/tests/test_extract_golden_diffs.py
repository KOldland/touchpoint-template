import json
import os
import tempfile
import unittest
import zipfile

from scripts.extract_golden_diffs import extract, to_html


class ExtractGoldenDiffsTest(unittest.TestCase):
    def test_extract_and_render_html(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            zip_path = os.path.join(tmp, "golden-diff.zip")
            summary = {
                "result": "failure",
                "mismatches": [
                    {"fixture": "generate_awareness_ok.json", "owner": "@ci-qa-team", "reason": "canonical_mismatch"}
                ],
            }
            patch_body = "--- a/expected.json\n+++ b/actual.json\n@@ -1 +1 @@\n-foo\n+bar\n"

            with zipfile.ZipFile(zip_path, "w") as zf:
                zf.writestr("golden-summary.json", json.dumps(summary))
                zf.writestr("generate_awareness_ok.diff.patch", patch_body)

            parsed_summary, patches = extract(zip_path)
            self.assertEqual("failure", parsed_summary.get("result"))
            self.assertEqual(1, len(patches))

            rendered = to_html(parsed_summary, patches)
            self.assertIn("Golden Diff Report", rendered)
            self.assertIn("generate_awareness_ok.json", rendered)
            self.assertIn("canonical_mismatch", rendered)


if __name__ == "__main__":
    unittest.main()
