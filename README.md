# VINA (Visual Interface for Network Assessment) Universiteit Utrecht
VINA is a web application developed by the HTS AppTeam of the Faculty of Social Science at Utrecht University.
It is based on a standaard laravel application with a Livewire frontend. Look at the laravel documentation for more 
information on how to use laravel and how to install this on a web server: https://laravel.com/docs

## HTS-Appteam base package
VINA uses the HTS-Appteam base package (https://github.com/UtrechtUniversity/HTS-AppTeam-LaravelBase), which is a 
collection of packages and tools that the HTS AppTeam uses to build applications. It includes a SAML IdP/OIDC and other 
tools that make development easier. This package is not strictly necessary to run VINA, so you could remove this. But 
then you have to implement the laravel login logic. If you wish to use the HTS-AppTeam base package, you can contact 
the appteam or access the repository on GitHub (htsappteam@uu.nl).

## Development

### Standalone local Docker setup

This option runs VINA and MariaDB on localhost without the shared development
network or reverse proxy. It builds the frontend assets and expects
`HTS-AppTeam-LaravelBase` to be checked out alongside this repository:

```
reviews/
  FSW-VINA/
  HTS-AppTeam-LaravelBase/
```

Docker Desktop and an internet connection to download the public Composer
dependencies are required. The base package comes from the adjacent checkout,
which may differ from the version recorded in `composer.lock`. Its source is
mounted read-only into the container.

From the VINA directory, create a local environment file only if you do not
already have one, then build and start the services:

```powershell
if (-not (Test-Path .env)) { Copy-Item .env.example .env }
docker compose -p vina-dev -f docker-compose.local.yml up -d --build
docker compose -p vina-dev -f docker-compose.local.yml exec web php artisan config:clear
docker compose -p vina-dev -f docker-compose.local.yml exec web php artisan package:discover
```

For a **new** environment file with an empty `APP_KEY`, generate a key once:

```powershell
docker compose -p vina-dev -f docker-compose.local.yml exec web php artisan key:generate
```

Do not regenerate an existing key: it invalidates encrypted application data.
On the first setup of the new database, run:

```powershell
docker compose -p vina-dev -f docker-compose.local.yml exec web php artisan migrate --force
docker compose -p vina-dev -f docker-compose.local.yml exec web php artisan db:seed --force
```

Package discovery must precede migration: the base package supplies additional
user-table migrations needed by the seeders. Seed only once; it is not
idempotent. The database is stored in a Docker volume and is not reset when
the containers restart.

Local Compose enables password login instead of OIDC. Set a password for the
seeded administrator (the command prompts without displaying the password):

```powershell
docker compose -p vina-dev -f docker-compose.local.yml exec web php artisan local:set-password daan@test.nl
```

Open http://localhost:8080/auth/local/login and sign in with `daan@test.nl`
and the password you chose. To generate a random password instead, add
`--generate`; it is shown once. The command can set a password only for an
existing user, and only when local password login is enabled. Registration and
password reset by email are disabled in this local setup.

Local Compose defaults to English, hides the environment ribbon and debug
output, and seeds the English questionnaire on a new database. Existing
questionnaires are not replaced: switching question sets can require removing
surveys and participants. See "Install questionaire" below before changing an
existing database. A previously selected browser language remains in the
session; clear the session or choose English to change it.

#### Roster and shared tablets

The standalone local Compose setup enables roster claims. Create the survey
and code in `/surveytable`, then add every participant under
`/surveystudenttable`, choosing the same survey for each. An administrator can
click **Set secret word** beside each participant and enter a five-letter
word using A-Z. Give each student their word privately; only its password hash
is stored. Changing the word revokes the previous credential and active
session. Existing 16-character codes no longer work; set a word for each
participant before they start or resume.

Administrators can instead select **Import survey and roster** on the survey
page and upload a UTF-8 CSV. Download the example on that page or use
[survey-roster.csv](examples/survey-roster.csv). The required columns, in
order: put only the new survey code on the first line, leave a blank line,
then add one `name,secret_word` row per participant, without a header. The
previous labelled formats are still accepted.
Include between 1 and 500 participants, with unique names and
five-letter words containing only A-Z. Quote names containing commas. The
file must be at most 256 KB. A failed import creates nothing, and existing
surveys cannot be overwritten.

The import creates a survey and roster for the questionnaire **already
installed** in VINA; it does not import or change the questions in
`database/en_questions.json`. The CSV contains names and plaintext words:
store and distribute it securely, then delete it when it is no longer needed.

Each participant enters the survey code, then their roster name and secret
word. Names must match the roster; words are case-insensitive.
The roster must be complete before anyone begins the selection questions.
Students can resume an unfinished questionnaire using the same word; claiming
it on another device ends the previous session. If interrupted, use **Leave
and clear this tablet** before handing it over; the word can be used to resume.
At the end, use **Finish and
clear this tablet** before handing it to the next student. A completed word
cannot be reused. If a follow-up Qualtrics survey is configured, its link is
shown after finishing; complete it before handing over the tablet.

Existing survey participants do not automatically have secret words; set
them from the roster screen. This mode is enabled with
`VINA_ROSTER_CLAIM=true`; other installations retain the existing self-entry
workflow unless explicitly enabled. Some questions also depend on classmates'
answers, so a complete roster alone does not ensure every such question has
all the information it needs. Review the questionnaire and study workflow
before collecting responses.

This development setup listens only on localhost, uses HTTP and is not
configured for real student data or access from tablets. A production
deployment needs HTTPS, access controls, protected backups, a reviewed data
retention schedule, and appropriate privacy approval. In particular, the
application's anonymization job replaces participant names with UUIDs; do not
run it until the group has finished using the roster.

Five-letter words are easy to guess, especially if children know one another's
names. Failed claims are limited per participant and per network address, but
this is not strong authentication. Choose distinct, non-obvious words, keep
them private, and supervise the tablets.

Existing local databases need `php artisan migrate --force` after updating
before setting a password. Do not rerun the seeders on an existing database.
The local-only migration retains its columns on rollback to avoid deleting
credentials. Other environments retain their original OIDC configuration.

Frontend assets are rebuilt with the image. After changing frontend source,
run `docker compose -p vina-dev -f docker-compose.local.yml up -d --build`
to refresh the assets.

Composer dependencies are stored in the `vina-dev_vendor` volume. After
changing `composer.lock`, rebuild the image and recreate that volume so it
receives the new dependencies (this does not delete the database):

```powershell
docker compose -p vina-dev -f docker-compose.local.yml down
docker volume rm vina-dev_vendor
docker compose -p vina-dev -f docker-compose.local.yml up -d --build
docker compose -p vina-dev -f docker-compose.local.yml exec web php artisan package:discover
docker compose -p vina-dev -f docker-compose.local.yml exec web php artisan migrate --force
```

To stop the services without deleting the database:

```powershell
docker compose -p vina-dev -f docker-compose.local.yml down
```

### Prerequisites

- In order to run (or build) a Docker container on your local machine you will need to install the 'Docker-for-Desktop'
client for your particular hardware setup. Clients are available for Windows, Mac OS X & Linux. They can be found at
https://www.docker.com/products/docker-desktop. Installation should be straight forward, instructions are provided at
the before mentioned website.

- add

  `127.0.0.1 dualnets.docker.dev`

  `127.0.0.1 saml-idp.docker.dev`

  to your hosts file.

### SAML IdP Container

Most of the projects & systems build by the HTS AppTeam need a (local) SAML IdP server to handle authentication.
To make life easier this container handles all that. You should be able to run this container and be good-to-go.

### Installation

Clone: https://github.com/UtrechtUniversity/HTS-Appteam-Development (develop branch)

When above mentions prerequisites are met, you should be able to build & run the container with the following command,
run in the HTS-Appteam-Development root folder:

`docker network create "hts-appteam-local-dev"`
`docker-compose up --build -d`

You should be able to access this SAML-IdP from your browser with the following URL https://saml-idp.docker.dev/


### Run development:
Clone this repo and run `docker-compose up --build -d` in the root folder.

### Testing
Tests can run from the host with `./test.sh` or from within the container with: `php artisan dusk`
You can see all dusk tests on the url: http://localhost:7900/  (password: `secret`)

## Running the application
### Run Survey Application:
Frontend is build with LiveWire to run the application you have to run the following commands:
- Run `composer install` or `composer update` to install dependencies
- Run `npm install`
- To build the css and js run `npm build`
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
