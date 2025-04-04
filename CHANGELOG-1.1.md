CHANGELOG for 1.1.x
===================

This changelog references any relevant changes introduced in 1.1 minor versions.

* 1.1.3 (2024-12-18)
    * Issue #74 - another error on update customer data.
    * Issue #73 - got error on customer create.
    * Issue #63 - IF space in last when create ticket then showing error.
    * Issue #62 - Should be shown a proper messages when using the session logout api.
    * Issue #55 - When create an agent from api side then group, team, agent-priv, ticket-view option added.
    * Issue #54 - When using session/login api then showing an error regarding UserAccessScopes.
    * Issue #45 - When we are using the expired token from session logout api then token not expired/disabled from admin panel.
    * Issue #44 - when we have multiple access token on our admin panel then copy the below token using copy button then always copy on the first token only.
    * Issue #40 - When replying from the customer account with using API, email not received on agent side.
    * Issue #39 - When replying with a note and forward option added to the customer account so should not be added as a note and forward in the tickets.
    * Issue #38 - When ticket is in the trashed tab should not be the agent updated.
    * Issue #32 - Ticket channel should be API when ticket created from API.
    
* 1.1.2 (2023-06-12)
    * Update: Dropped dependency on uvdesk/composer-plugin in support of symfony/flex
    * Feature: Add api endpoints for sessions, me, agents, customers, groups, teams, ticket, threads, and ticket-type helpdesk resources.

* 1.1.1.1 (2023-02-14)
    * Feature: Add support for api authentication using both Basic/Bearer header token values

* 1.1.1 (2022-09-13)
    * Bug Fixes: Entity reference updates and other miscellaneous bug fixes

* 1.1.0 (2022-03-25)
    * Feature: Improved compatibility with PHP 8 and Symfony 5 components
    * Bug #29: Update ticket data serializer (papnoisanjeev)
