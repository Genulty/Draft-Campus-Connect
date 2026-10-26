127.0.0.1:3306/campus_connect/Course_Section/		https://3-239-161-185.sslip.io/phpmyadmin/index.php?route=/database/sql&db=campus_connect

   MySQL returned an empty result set (i.e. zero rows). (Query took 0.0014 seconds.)



   Showing rows 0 -  0 (1 total, Query took 0.0005 seconds.)


-- Increase the maximum GROUP_CONCAT output size
SET SESSION group_concat_max_len = 1000000;


-- Generate the complete migration
SELECT GROUP_CONCAT(
    CONCAT(
        'UPDATE Course_Section SET time_Slot_ID = ',
        time_Slot_ID,
        ' WHERE CRN = ',
        CRN,
        ' AND semester_ID = ''FA26'';'
    )
    ORDER BY CRN
    SEPARATOR '\n'
) AS complete_migration
FROM Course_Section
WHERE semester_ID = 'FA26';



UPDATE Course_Section SET time_Slot_ID = 25 WHERE ...	
