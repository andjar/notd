<?php
// Project Setup Page Template
// Contains multiple notes that will be created when this template is used
?>
[
    {
        "title": "Project Overview: {{project_name}}",
        "content": "# Project Overview\n\n**Project Name:** {{project_name}}\n**Start Date:** {{date}}\n**Project Manager:** {{project_manager}}\n\n## Project Description\n{{project_description}}\n\n## Goals\n- \n- \n\n## Timeline\n- Start: {{date}}\n- Target Completion: {{completion_date}}\n\n---\n*Created on {{datetime}}*"
    },
    {
        "title": "Team Members",
        "content": "# Team Members\n\n## Core Team\n{{team_members}}\n\n## Roles and Responsibilities\n- \n- \n\n## Contact Information\n- \n\n---\n*Created on {{datetime}}*"
    },
    {
        "title": "Project Setup Checklist",
        "content": "# Project Setup Checklist\n\n## Initial Setup\n- [ ] Create project repository\n- [ ] Set up development environment\n- [ ] Define coding standards\n- [ ] Create project documentation structure\n\n## Infrastructure\n- [ ] Set up CI/CD pipeline\n- [ ] Configure development servers\n- [ ] Set up monitoring\n\n## Documentation\n- [ ] Create README\n- [ ] Document API specifications\n- [ ] Create user documentation structure\n\n---\n*Created on {{datetime}}*"
    }
] 