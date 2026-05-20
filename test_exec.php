<?php
exec('"C:\\Program Files\\LibreOffice\\program\\soffice.exe" --version', $out, $code);
echo '<pre>';
echo 'Return code: ' . $code . '<br>';
echo implode('<br>', $out);
echo '</pre>';