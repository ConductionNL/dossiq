# The workload names the reader's teams

## Why
`GET /api/work-queue/workload` counted the open cases of every assignee in the register, so a coordinator's workload chips listed test users, admins and people from other teams. Boards DqAanMijToegewezen and DqTeamwachtrij draw only the people of the reader's team.

## What changes
- The workload keeps the handlers who are members of one of the reader's teams (the teams `PrimaryTeamService::teamsOf` answers, the same ones the team queue reads). Each handler still counts all of their open cases, and a member without open cases is listed with 0, so a free colleague shows.
- A reader in no team keeps the whole list, as the team queue does. When the teams can't be read, the list is not narrowed.
- The MCP reporting tool keeps the register-wide count.
