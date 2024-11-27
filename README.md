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

Getting Started
--------------

* [Ticket Related APIs][6]

License
--------------

The API Bundle and libraries included within the bundle are released under the [OSL-3.0 license][7]

[1]: https://www.uvdesk.com/
[2]: https://symfony.com/
[3]: https://symfony.com/4
[4]: https://flex.symfony.com/
[5]: https://getcomposer.org/
[6]: https://github.com/uvdesk/api-bundle/wiki/Ticket-Related-APIs
[7]: https://github.com/uvdesk/api-bundle/blob/master/LICENSE.txt
