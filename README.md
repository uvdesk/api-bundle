<p align="center"><a href="https://www.uvdesk.com/en/" target="_blank">
    <img src="https://s3-ap-southeast-1.amazonaws.com/cdn.uvdesk.com/uvdesk/bundles/webkuldefault/images/uvdesk-wide.svg">
</a></p>

The API bundle allows integration developers to utilize uvdesk's REST api to easily communicate with their community helpdesk system.

Installation
--------------

This bundle can be easily integrated into any symfony application (though it is recommended that you're using [Symfony 4][3] as things have changed drastically with the newer Symfony versions). Before continuing, make sure that you're using PHP 7.2 or higher and have [Composer][5] installed. 

To require the api bundle into your symfony project, simply run the following from your project root:

```bash
$ composer require uvdesk/api-bundle
```
After installing api bundle run the below command:

```bash
$ php bin/console doctrine:schema:update --force
```
Finally clear your project cache by below command(write prod if running in production environment i.e --env prod):

```bash
$ php bin/console cache:clear --env dev
```

API References
--------------

Use the below available apis to interact with your helpdesk system.

## Session:


- POST **/api/v1/session/login**

    Authenticate user credentials and generate an access token in response to access helpdesk apis as the authenticated user.

    Headers:
    
    ```
    {
        "Authorization": "Bearer AUTH_TOKEN"
    }
    ```

    > To generate the required auth token, simply encode the user's **email** and **password** separated by a *colon* in base64 format, *i.e. base64_encode("email:password")*.

    Sample Response:

    ```
    {
        "success": true,
        "accessToken": "VBGLASXENR..."
    }
    ```

- POST **/api/v1/session/logout**

    Invalidates an authenticated user access token so that it can't be used anymore.

    Headers:
    
    ```
    {
        "Authorization": "Bearer ACCESS_TOKEN"
    }
    ```

    > **ACCESS_TOKEN**: The api access token that was generated either using the session login api or directly from the dashboard.

    Sample Response:

    ```
    {
        "status": true,
        "message": "Session token has been expired successfully."
    }
    ```

## Tickets:

More examples of ticket related apis can be found over [here][6].

- GET **/tickets**

    Get a collection of all user accessible tickets.

    Headers:
    
    ```
    {
        "Authorization": "Bearer ACCESS_TOKEN"
    }
    ```

    > **ACCESS_TOKEN**: The api access token that was generated either using the session login api or directly from the dashboard.

    Sample Response:

    ```
    {
        "tickets": [
            {
                "id": 1,
                "subject": "Support Request ...",
                "isCustomerView": false,
                "status": {
                    "id": 1,
                    "code": "open",
                    "description": "Open",
                    "colorCode": "#7E91F0",
                    "sortOrder": 1
                },
                "group": null,
                "type": {
                    "id": 1,
                    "code": "support",
                    "description": "Support",
                    "isActive": true
                },
                "priority": {
                    "id": 1,
                    "code": "low",
                    "description": "Low",
                    "colorCode": "#2DD051"
                },
                "formatedCreatedAt": "16-05-2023 12:55pm",
                "totalThreads": "0",
                "agent": {
                    "id": 1,
                    "email": "agent@example.com",
                    "name": "Sample Agent",
                    "firstName": "Sample",
                    "lastName": "Agent",
                    "isEnabled": true,
                    "profileImagePath": null,
                    "smallThumbnail": null,
                    "isActive": true,
                    "isVerified": true,
                    "designation": null,
                    "contactNumber": null,
                    "signature": null,
                    "ticketAccessLevel": null
                },
                "customer": {
                    "id": 2,
                    "email": "customer@example.com",
                    "name": "Sample Customer",
                    "firstName": "Sample",
                    "lastName": "Customer",
                    "contactNumber": null,
                    "profileImagePath": null,
                    "smallThumbnail": null
                }
            }
        ],
        "pagination": {
            "last": 1,
            "current": 1,
            "numItemsPerPage": 15,
            "first": 1,
            "pageCount": 1,
            "totalCount": 1,
            "pageRange": 1,
            "startPage": 1,
            "endPage": 1,
            "pagesInRange": [
                1
            ],
            "firstPageInRange": 1,
            "lastPageInRange": 1,
            "currentItemCount": 1,
            "firstItemNumber": 1,
            "lastItemNumber": 1,
            "url": "#page/replacePage"
        },
        "userDetails": {
            "user": 1,
            "name": "Sample Agent"
        },
        "agents": [
            {
                "id": 1,
                "udId": 1,
                "email": "agent@example.com",
                "name": "Sample Agent",
                "smallThumbnail": null
            }
        ],
        "status": [
            {
                "id": 1,
                "code": "open",
                "description": "Open",
                "colorCode": "#7E91F0",
                "sortOrder": 1
            },
            {
                "id": 2,
                "code": "pending",
                "description": "Pending",
                "colorCode": "#FF6A6B",
                "sortOrder": 2
            },
            {
                "id": 3,
                "code": "answered",
                "description": "Answered",
                "colorCode": "#FFDE00",
                "sortOrder": 3
            },
            {
                "id": 4,
                "code": "resolved",
                "description": "Resolved",
                "colorCode": "#2CD651",
                "sortOrder": 4
            },
            {
                "id": 5,
                "code": "closed",
                "description": "Closed",
                "colorCode": "#767676",
                "sortOrder": 5
            },
            {
                "id": 6,
                "code": "spam",
                "description": "Spam",
                "colorCode": "#00A1F2",
                "sortOrder": 6
            }
        ],
        "group": [
            {
                "id": 1,
                "name": "Default"
            }
        ],
        "team": [],
        "priority": [
            {
                "id": 1,
                "code": "low",
                "description": "Low",
                "colorCode": "#2DD051"
            },
            {
                "id": 2,
                "code": "medium",
                "description": "Medium",
                "colorCode": "#F5D02A"
            },
            {
                "id": 3,
                "code": "high",
                "description": "High",
                "colorCode": "#FA8B3C"
            },
            {
                "id": 4,
                "code": "urgent",
                "description": "Urgent",
                "colorCode": "#FF6565"
            }
        ],
        "type": [
            {
                "id": 1,
                "name": "support"
            }
        ],
        "source": {
            "email": "Email",
            "website": "Website"
        }
    }
    ```

--------------

License
--------------

The API Bundle and libraries included within the bundle are released under released under the [OSL-3.0 license][7]

[1]: https://www.uvdesk.com/
[2]: https://symfony.com/
[3]: https://symfony.com/4
[4]: https://flex.symfony.com/
[5]: https://getcomposer.org/
[6]: https://github.com/uvdesk/api-bundle/wiki/Ticket-Related-APIs
[7]: https://github.com/uvdesk/api-bundle/blob/master/LICENSE.txt
