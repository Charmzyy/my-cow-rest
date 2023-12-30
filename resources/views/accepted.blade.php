<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Cattle Prediction Certificate</title>
  <!-- Bootstrap CSS -->
  <link href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
  <style>
    /* Custom styles for the cattle certificate */
    .certificate {
      border: 2px solid #FFA500; /* Orange border */
      padding: 20px;
      margin: 50px;
      position: relative;
      font-family: Arial, sans-serif;
    }
    .certificate h2 {
      text-align: center;
      text-transform: uppercase;
      margin-bottom: 20px;
      color: #FFA500; /* Orange text color */
    }
    .certificate p {
      text-align: center;
      font-size: 18px;
      color: #555; /* Darker text color */
    }
    .prediction {
      text-align: center;
      margin-top: 20px;
    }
    .prediction img {
      max-width: 100%;
      height: auto;
      margin-bottom: 10px;
    }
  </style>
</head>
<body>

  <div class="container">
    <div class="certificate">
      <h2>Cattle Prediction Certificate</h2>
      <p>This certificate is awarded to</p>
      <h3>{{ $data['post']->user->name }}</h3>
      <p>For a Pure Breed Cattle </p>

      <!-- Replace these placeholders with actual values -->
      <h4>Cow Name: {{ $data['post']['cow_name'] }}</h4>
      <div class="prediction">
       
       

        <p>Prediction: {{ $data['post']['prediction'] }}</p>
        <p>Confidence:  {{ $data['post']['confidence'] }}</p>
      </div>
      
      <div class="signature">
        <p>Authorized Signature</p>
        <!-- You can add an image of a signature here if needed -->

      </div>
    </div>
  </div>

</body>
</html>
