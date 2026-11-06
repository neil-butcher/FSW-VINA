# VINA (Visual Interface for Network Assessment) Universiteit Utrecht
VINA is a web application developed by the HTS AppTeam of the Faculty of Social Science at Utrecht University.
It is based on a standaard laravel application with a Livewire frontend. Look at the laravel documentation for more 
information on how to use laravel and how to install this on a web server: https://laravel.com/docs

## Authentication
VINA uses Laravel session authentication with local email/password accounts.
The private HTS-AppTeam base package and a SAML/OIDC identity provider are no longer
required. University single sign-on has been replaced by local login at `/login`.
There is no public registration or password-reset email flow; operators manage
accounts using `php artisan app:local-user email@example.com` (see below).

## Development

### Prerequisites

- In order to run (or build) a Docker container on your local machine you will need to install the 'Docker-for-Desktop'
client for your particular hardware setup. Clients are available for Windows, Mac OS X & Linux. They can be found at
https://www.docker.com/products/docker-desktop. Installation should be straight forward, instructions are provided at
the before mentioned website.

- add

  `127.0.0.1 dualnets.docker.dev`

  to your hosts file.

### Legacy development stack

The existing development Compose configuration still uses the external
`hts-appteam-local-dev` network. Create it with
`docker network create hts-appteam-local-dev` if using that stack.
The standalone runtime image below avoids this development setup entirely.


### Run development:
Clone this repo and run `docker-compose up --build -d` in the root folder.

### Testing
Tests can run from the host with `./test.sh` or from within the container with: `php artisan dusk`
You can see all dusk tests on the url: http://localhost:7900/  (password: `secret`)

After installing Composer development dependencies, the local authentication tests
can run with `vendor/bin/phpunit tests/Feature/LocalAuthenticationTest.php`.
They use an isolated in-memory SQLite database and require the PDO SQLite extension.

## Running the application
### Windows: setup through login

Install and start Docker Desktop with Linux containers, then double-click
`start-vina.bat` (or run it from a terminal). It builds the runtime image, starts
MariaDB, generates configuration and an application key, runs migrations, starts
the translation setup to load missing survey text and labels, starts
the web server, prompts for an administrator email/password, starts the scheduler,
and opens http://localhost:8080/login. Port 8080 must be available.

Only Docker Desktop and Windows PowerShell are needed on the host. Allow the first
build a few minutes. The script stops on errors and displays the cause.
Rerunning it keeps the application key, database credentials, database and survey
files; it prompts again to create or set an administrator password. It does not
install optional demo data. After login, use `/install-questions` and
`/settingtable` as described below to configure the questionnaire.

Generated configuration is stored in the ignored `.vina-local` directory; keep it
private and back it up together with the Docker volumes `vina-local_database` and
`vina-local_storage`. Keep this folder when restarting or updating the application.
To stop the services while keeping data, run:

```sh
docker compose --project-name vina-local --file .vina-local/compose.yaml stop
```

### Runtime Docker image

The runtime image builds Composer dependencies and frontend assets in separate stages.
It contains PHP/Apache and the application, without Node, Composer, browser tests,
development certificates or the development Docker network. Only Docker is needed
on the host, along with a reachable MySQL/MariaDB database. No university package
access or identity-provider service is needed.

Build from the repository root:

```sh
docker build -f docker/runtime.dockerfile -t vina:runtime .
```

Copy `.env.example` to `.env.runtime` and configure `APP_ENV=production`,
`APP_DEBUG=false`, `APP_URL=http://localhost:8080` and database credentials.
`DB_HOST` must be reachable from the
container; use `host.docker.internal` for a database on the Docker Desktop host,
or a database container's name on a shared Docker network.
Generate an application key and put the printed value in `.env.runtime` as `APP_KEY`.
Keep this key stable across restarts:

```sh
docker run --rm vina:runtime php artisan key:generate --show
```

Initialize the database, then start the web server:

```sh
docker run --rm --env-file .env.runtime vina:runtime php artisan migrate --force
docker run -d --name vina --env-file .env.runtime -p 8080:80 -v vina-storage:/var/www/html/storage vina:runtime
```

For optional demo data, run this on a fresh database before creating local
accounts (the demo seeder inserts fixed roles/users):

```sh
docker exec --user www-data vina php artisan db:seed --force
```

Create your administrator account interactively (passwords are prompted and hashed):

```sh
docker exec -it --user www-data vina php artisan app:local-user admin@example.com --name="Administrator" --admin
```

Open http://localhost:8080/login, sign in and follow the questionnaire installation
instructions below. Use the same command with an existing email to set a password
for an account previously using university SSO. Omit `--admin` for ordinary users;
existing roles are retained unless `--admin` is supplied. Existing and demo accounts
cannot log in locally until you set their passwords. There are no default passwords.

The survey introduction and some labels are stored as database translations.
The Windows setup installs these automatically. For manual setup, run:

```sh
docker exec --user www-data vina php artisan db:seed --class=TranslationsTableSeeder --force
```

This adds missing translations and preserves existing wording. Without these
translations, students can see keys such as `form.step_intro` instead of text.

The image serves HTTP; use an HTTPS reverse proxy for a public deployment.
Startup preserves the database.

Run the scheduler in a separate container using the same configuration and storage:

```sh
docker run -d --name vina-scheduler --env-file .env.runtime -v vina-storage:/var/www/html/storage vina:runtime php artisan schedule:work
```

This runs the schedules currently defined in `app/Console/Kernel.php`. Back up the
database and the `vina-storage` volume as appropriate.

### Run Survey Application:
Frontend is build with LiveWire to run the application you have to run the following commands:
- Run `composer install` or `composer update` to install dependencies
- Run `npm install`
- To build the css and js run `npm run build`
- For the database setup run `php artisan migrate`
- If you want to use survey demo data run `php artisan migrate --seed`

### Install questionaire
The application has two questionnaires: Default (dutch) and English. You can install the questionnaire via the 
url: {your url}/install-questions. Only one questionnaire can be installed at a time. After installing the 
questionnaire you can switch, but you have to remove all existing surveys, participant and results before you switch.

For the questionnaire to work properly you need to define a setting on the page {your url}/settingtable. Add a new 
setting with key: 'locale' and value: 'nl' or 'en', depending on the questionnaire you chose.

### Modify the questionnaire
At the url {your url}/beheer you can manage the questions and answers of the questionnaire.
At the url {your url}/surveyquestiontable you can see all the questions in your questionnaire. At this page you can enable or disable questions.
You can also add/remove/edit questions. You can do this by modifying the json files /database/questions.json or database/en_questions.json for the english version.
After modifying the json files, you can import the question via the url {your url}/import-questions.

#### Question types (form_type)
- `text` - Open text question
- `select` - Basic multiple choice question
- `select_students` - Questions with students as options to choose from
- `select_for_subject` - Questions with students as options to choose from for each student
- `select_multiple` -  Multiple choice question for multiple students
- `display` - Display question with text, this is not really a question but a text display

#### Mobile Web App
The survey app is build for mobile web app tested on iPhone X and 11 with Safari browser. Should work on other browsers but focus is with Safari and Chrome. 
Code for the survey questions and answers lives in directory `app/Livewire/Forms` and frontend in `resources/views/livewire/forms`
Survey steps are controlled by the StepController
The html intro page can be edited in `form-step-intro.blade`

###
### Survey CSV Export 
Cron will run once per hour to check if students are finished with a particular survey from classId. This will check the latest submission from the student, if this is longer than 1 hour ago than the survey results will be exported to the csv.

The naming of the exported csv file is `YY-mm-dd time classId`. Results are exported hourly per classId when there are new submissions.

There is also an option to run the csv export script simply from the url route. Open the link `{mydomain}/csv-export`. Don't forget to wait 1 hour after the survey submission.

###
### Data Privacy 
All the students names will be deleted after the survey results export in the database. This is running once per hour. 
The complete info for all survey answers and students will be deleted daily at `03:00`. After this there is no survey data in the database. 

###
### Cron Jobs 
The schedules for the cron are in the Kernel.php
There are 2 commands to run on the backend to manually run the cvs export and data delete script. 
Run `php artisan list` to see the commands under app. 

To run the survey csv export script run command: `php artisan app:export-csv-run`
To delete all survey database data run command : `php atrtisan app:delete-all-survey-data`
To start the schedule worker run command: `php arisan schedule:work` has also `-q` to run quite 
###

#### _Enjoy the app!_ :heart:
