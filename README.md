# Language field export/import for ProcessWire

Typically the way you translate page field values in ProcessWire is to edit a page,
view the text in one language, and translate the text into another language, 
directly in the page editor. 

This module provides an alternative to that process, moving the translation task
out of the ProcessWire admin and enabling it to be completed with external tools. 
It does this by make the multi-language field values exportable and importable
via JSON and/or CSV files. 

For more details, please please see the dedicated documentation post at: 
<https://processwire.com/blog/posts/language-field-export-import/>

### Requirements

- ProcessWire 3.0.200+
- Multi-language support installed
- One or more muti-language fields using supported field types


### Installation

1. Extract and copy the files from this module into: `/site/modules/ProcessLanguageFieldExportImport/`
 
2. In the ProcessWire admin, go to “Modules > Refresh”.  
 
3. Locate this module on the “Site” tab in “Modules” and click “Install”. 
 
4. The module will now be available at “Setup > Translation export/import”.

### Documentation

- [View the documentation](https://processwire.com/blog/posts/language-field-export-import/)
 
-----
*Copyright 2022 - Developed by Ryan Cramer Design, LLC*