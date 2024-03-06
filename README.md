# My Laravel App

This is a Laravel application that serves as the backend for a My cow breed  classification system. It connects to a FastAPI service for handling image submissions and user actions. Users can log in, submit images of cows, and view classification results. Administrators have the ability to view all posts and manually certify accurate classifications.

## Features
- User authentication: Users can register, log in, and log out securely.
- Image submission: Users can submit images of cows for classification.
- Classification results: Users can view classification results for their submissions.
- Automatic certfications : User mailed in with proper certification once cow is vetted successfully.
- Admin dashboard: Administrators can view all submissions and manually certify accurate classifications.

## Setup

1. Clone the repository: git clone <https://github.com/Charmzyy/my-cow-rest>
2.  Install dependencies:  PHP 8.2.12 , Composer  .
3.  Migrate Database : php artisan migrate.
4.  Start Server : php artisan serve --port=8001

## API Documentation

Explore our API endpoints and test them using Postman:

[Postman Documentation](<https://www.postman.com/cryosat-geoscientist-7709021/workspace/cowbreedclassification>)

Please note that this project is no longer live. You can host it on your own server and use the provided Postman documentation to interact with the API endpoints.

## Other Repositories

- [FastAPI Service](<https://github.com/Charmzyy/my-cow-py>): Backend service for image classification using FastAPI.
- [Frontend Repository](<https://github.com/Charmzyy/my-cow-client>): Frontend application for interacting with the classification system.






