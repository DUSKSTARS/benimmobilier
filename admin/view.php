<?php
$propertyId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if ($propertyId && $propertyId > 0) {
    header('Location: ../property.php?id=' . $propertyId);
} else {
    header('Location: ../mon-espace.php');
}
exit;
