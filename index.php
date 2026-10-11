<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>UniStay</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=Fraunces:opsz,wght@9..144,700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/landingpage.css">

</head>
<body>
<div class="page">
  <div class="blob" style="left:-60px;top:80px;width:300px;height:260px;background:#FBF1D5"></div>
  <div class="blob" style="right:-80px;top:300px;width:240px;height:300px;background:#FDE6D8"></div>
  <div class="blob" style="left:60px;top:880px;width:300px;height:300px;background:#FBF1D0"></div>
  <div class="blob" style="right:-120px;top:1040px;width:280px;height:280px;background:#F4E7D8"></div>
  <div class="blob" style="left:330px;top:1520px;width:300px;height:260px;background:#F6E9DC"></div>
  <div class="blob" style="right:-60px;top:1760px;width:260px;height:320px;background:#FDE6D8"></div>
  <div class="blob" style="left:-80px;top:1980px;width:260px;height:260px;background:#FBF1D0"></div>

  <nav>
    <a class="logo" href="#"><i></i><img id="UniStay" src="assets/images/logo.png" style="width: 50px; height: 50px;" alt="logo"></i>UniStay</a>
    <div class="links"><a href="#">Home</a><a href="#roles">Roles</a><a href="#tools">Tools</a></div>
    <div class="actions">
      <a class="loginbtn" href="#">Log in</a>
      <a class="btn" href="#">Sign up as Tenant</a>
      <a class="btn" href="#">Apply as Landlord</a>
    </div>
  </nav>

  <header class="hero">
    <div class="sq1"></div><div class="sq2"></div>
    <h1>Headline Headline Headline Headline Headline</h1>
    <p>Description Description Description Description Description Description Description Description Description Description Description Description</p>
    <div class="ctas"><a class="btn primary" href="#">Find Room</a><a class="btn soft" href="#">List a Property</a></div>
    <div class="img1"></div>
    <div class="tag"></div><div class="ring"></div><div class="dot1"></div><div class="dot2"></div>
  </header>

  <section class="sec">
    <h2 id="roles">Headline Headline Headline Headline Headline Headline</h2>
    <p class="desc">Description Description Description Description Description Description Description Description Description Description</p>
    <div class="tabs" role="tablist">
      <button class="tab active" data-t="tenant" role="tab">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 10 12 5 2 10l10 5 10-5z"/><path d="M6 12v5c3 2 9 2 12 0v-5"/></svg>Tenant Experience</button>
      <button class="tab" data-t="landlord" role="tab">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21V10l6-4 6 4v11M15 21V13h6v8M2 21h20"/></svg>Landlord Management</button>
    </div>
    <div class="panel" id="tenant"></div>
    <div class="panel" id="landlord" hidden></div>
  </section>

  <section class="sec cards">
    <h2 id="tools">Headline</h2>
    <p class="desc" style="max-width:200px">Description Description</p>
    <div class="grid"><div class="card"></div><div class="card"></div><div class="card"></div><div class="card"></div><div class="card"></div><div class="card"></div></div>
  </section>
</div>

<footer>
  <div class="f">
    <div>
      <a class="logo" href="#"><i></i><img id="UniStay" src="assets/images/logo.png" style="width: 50px; height: 50px;" alt="logo"></i></i>UniStay</a>
      <p>Description Description Description Description Description Description Description Description Description Description Description Description</p>
      <div class="news">
        <b>Stay connected!</b>
        <form id="sub"><input type="email" placeholder="Description" aria-label="Email"><button id="sub-btn">Subscribe</button></form>
      </div>
    </div>
    <div><h4>Product</h4><ul><li><a href="#">Room Finder</a></li><li><a href="#">Property Lister</a></li><li><a href="#">Tenant Dashboard</a></li><li><a href="#">Landlord Dashboard</a></li><li><a href="#">Features</a></li></ul></div>
    <div><h4>Company</h4><ul><li><a href="#">About Us</a></li><li><a href="#">Our Mission</a></li><li><a href="#">Contact Us</a></li></ul></div>
    <div><h4>Legal</h4><ul><li><a href="#">Privacy Policy</a></li><li><a href="#">Terms of Service</a></li><li><a href="#">Terms of Use</a></li><li><a href="#">Cookie Policy</a></li><li><a href="#">Community Guidelines</a></li></ul></div>
  </div>
  <div class="copy">© 2026 UniStay. Developed by Code of Duty. All Rights Reserved.</div>
</footer>

<script src="assets/js/landing-page.js"></script>
</body>
</html>