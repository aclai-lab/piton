# piton
A set of classification learners for the Laravel framework.

# Install

Add this github project as a repository in composer.json:

```json
"repositories": [
	{
		"type": "vcs",
		"url": "https://github.com/aclai-lab/piton"
	}
]

```

Add the package in require section:

```json
"require": {
	"aclai/piton": "master"
}
```

And in the terminal, run the following command:

```
composer update
```

### Note: for *dev* installation, it is actually more convenient to clone this repo in a directory, and tell composer.json to use that distribution, before running `composer update`:

```json
"repositories": [
	{
		"type": "path",
		"url": "{PATH of piton repo}"
	}
],

...

"require": {
	"aclai/piton": "dev"
}
```

### Config a PITON database

piton is meant to be completely separated from your project, and to save all it's data, especially models, to another database, so a new connection is required.
To do so, you have to add a new connection in the 'connections' array of your `config/database.php` file. If you're using MySQL, you can use the following stub.
```json
'piton_connection' => [
            'driver' => env('DB_CONNECTION_PITON'),
            'host' => env('DB_HOST_PITON', '127.0.0.1'),
            'port' => env('DB_PORT_PITON', '3306'),
            'database' => env('DB_DATABASE_PITON', 'forge'),
            'username' => env('DB_USERNAME_PITON', 'forge'),
            'password' => env('DB_PASSWORD_PITON', ''),
            'unix_socket' => '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
        ],
```

You also have to add the following to your `.env ` file.
```json
DB_CONNECTION_PITON=mysql
DB_HOST_PITON=127.0.0.1
DB_PORT_PITON=3306
DB_DATABASE_PITON=<your_piton_database>
DB_USERNAME_PITON=<your_mysql_username>
DB_PASSWORD_PITON=<your_mysql_password>
```

(Other stubs will be add in the future for full support).

At the moment, only MySQL is tested and supported.


### Migrate Database

Now that we have our package installed, we need to migrate the database to add the necessary tables for piton. In the command line, run the following command.

`php artisan migrate`

### Publish the package config

Up next, you need to publish the package's config file that includes some defaults for us. To publish that, run the following command.

`php artisan vendor:publish --tag=problem-config`

You will now find the config file located in `/config/problem.php`

There, you can specify how to build the object of type Instances (basically, a table with metadata) on which you can create rule-based models.

# A simple example
If you want to try the package right away, you can run the following command:

`php artisan piton:create_example`

This will create a table called `Iris` in your database, containing the famous Iris dataframe.
Then, you'll have to launch the following command:


`php artisan vendor:publish --tag=iris-config`

This will create a file called `iris.php` in your project `config` directory.
This config file equals to a problem configuration based on the iris dataframe!

Now, you can try to use one of our learners. For example, let's try to use PRip.
First, we have to publish its configuration file via:
`php artisan vendor:publish --tag=prip-config`.

You can now modify `config/prip.php` specifying the options you that prefer; for now, let's keep it as it is.
We can now launch the command: `php artisan piton:update_models iris <author_id> PRip` which take as parameters the problem to be solved (i.e., the name of the configuration file withouth the .php extension, in this case iris), the author id, the chosen learner and eventually which specific algorithm of the learner (in this case we specify "Prip" as the chosen learner).

This will create as many models as your class attributes (remember that categorical attributes will be forced to be binary, so an attribute with 3 different values will result in 3 separate class attributes, one for each value). In this case, it should create 3 models: one for "Species_setosa", one for "Species_versicolor" and one for "Species_virginica".

We can now try to predict on these results launching: `php artisan piton:predict_by_identifier` and specifying an identifier.

Suggestion: with the iris dataframe, we sugget using SKLearnLearner CART for accurate predictions. To do so, first publish the config file: `php artisan --tag=sklearn_cart.php` (remember, there's a config file for each "algorithm", only "PRip" has just one config file) and then run `php artisan piton:update_models  iris <author_id> SKLearnLearner CART`.

# Dependencies

If you want to use the SKLearnLearner and the WittgensteinLearner learners, you also have to make sure that the follow dependencies are on your machine:

- numpy=1.19.2
- pandas=1.2.3
- sqlalchemy=1.3.23
- pymysql=1.0.2
- scikit-learn=0.24.1
- wittgenstein==0.2.3
