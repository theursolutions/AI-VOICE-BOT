<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Login / Signup - NeuraLink AI</title>
<link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@500;700&family=Poppins:wght@300;400;600&display=swap" rel="stylesheet" />
<style>
  body, html {
    margin: 0;
    padding: 0;
    font-family: 'Poppins', sans-serif;
    color: #fff;
    height: 100vh;
    overflow: hidden;
    position: relative;
  }

  /* Background GIF */
  body::before {
    content: '';
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: url('https://media4.giphy.com/media/v1.Y2lkPTc5MGI3NjExNXNzZ3l1eHBtMXh0bzZ2bnNvMnQ5NXJiZnYyNmZzOXV5ZzZoMnUwayZlcD12MV9pbnRlcm5hbF9naWZfYnlfaWQmY3Q9Zw/7VzgMsB6FLCilwS30v/giphy.gif') center/cover no-repeat;
    z-index: -2;
    opacity: 0.6;
  }

  /* Black Overlay */
  body::after {
    content: '';
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.6); /* dark overlay */
    z-index: -1;
  }

  .container-wrapper {
    display: flex;
    justify-content: center;
    align-items: center;
    height: 100vh;
  }

  .container {
    width: 100%;
    max-width: 500px;
    background: rgba(0, 0, 0, 0.65);
    backdrop-filter: blur(20px);
    border-radius: 20px;
    padding: 40px;
    text-align: center;
    animation: fadeIn 1s ease;
  }

  .container h1 {
    font-family: 'Orbitron', sans-serif;
    background: linear-gradient(90deg, #00d2ff, #3a7bd5);
    -webkit-background-clip: text;
    color: transparent;
    font-size: 2rem;
    margin-bottom: 20px;
  }

  .toggle-btns {
    display: flex;
    justify-content: center;
    margin-bottom: 10px;
  }

  .toggle-btns button {
    flex: 1;
    background: none;
    border: none;
    color: rgba(255, 255, 255, 0.7);
    font-size: 1.2rem;
    margin: 0 10px;
    cursor: pointer;
    border-bottom: 3px solid transparent;
    padding: 10px 0;
    transition: all 0.3s;
  }

  .toggle-btns button.active {
    color: #fff;
    border: 3px solid #00d2ff;
    font-weight: 600;
    border-radius: 30px;
  }

  form {
    display: flex;
    flex-direction: column;
  }

  input {
    background: rgba(255, 255, 255, 0.1);
    border: none;
    padding: 15px;
    margin: 10px 0;
    border-radius: 10px;
    color: #fff;
    outline: none;
    font-size: 1rem;
  }

  .submit-btn, .google-btn {
    background: linear-gradient(90deg, #00d2ff, #3a7bd5);
    color: #0a0a1a;
    font-weight: bold;
    border: none;
    padding: 15px;
    border-radius: 30px;
    cursor: pointer;
    margin-top: 20px;
    font-size: 1.1rem;
    transition: transform 0.3s;
    width: 100%;
  }

  .submit-btn:hover, .google-btn:hover {
    transform: translateY(-3px);
  }

  .google-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    background: #fff;
    color: #0a0a1a;
    font-weight: 600;
  }

  .google-btn img {
    width: 22px;
    height: 22px;
  }

  .form-container {
    display: none;
  }

  .form-container.active {
    display: block;
  }

  @keyframes fadeIn {
    from { opacity: 0; transform: scale(0.95); }
    to { opacity: 1; transform: scale(1); }
  }
</style>
</head>
<body>
  <div class="container-wrapper">
    <div class="container">
      <h1>NeuraLink AI</h1>
      <div class="toggle-btns">
        <button id="loginToggle" class="active">Login</button>
        <button id="signupToggle">Signup</button>
      </div>
      <!-- Login Form -->
      <div class="form-container active" style="text-align:start;" id="loginForm">
        <form method="POST" action="{{ route('login') }}">
            @csrf
          
            <label for="email" class="col-md-4 col-form-label text-md-end">{{ __('Email Address') }}</label>
            <input id="email" type="email" placeholder="Enter Email" class="form-control1 @error('email') is-invalid @enderror" name="email" value="{{ old('email') }}" required autocomplete="email" autofocus>
            @error('email')
                <span class="invalid-feedback" role="alert">
                    <strong>{{ $message }}</strong>
                </span>
            @enderror
            
            <label for="password" class="col-md-4 col-form-label text-md-end">{{ __('Password') }}</label>
            <input id="password" type="password" placeholder="Enter Password" class="form-control @error('password') is-invalid @enderror" name="password" required autocomplete="current-password">
            @error('password')
                <span class="invalid-feedback" role="alert">
                    <strong>{{ $message }}</strong>
                </span>
            @enderror
               
            <div class="row mb-2">
                <div class="col-md-6 offset-md-4">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="remember" id="remember" {{ old('remember') ? 'checked' : '' }}>

                        <label class="form-check-label" for="remember">
                            {{ __('Remember Me') }}
                        </label>
                    </div>
                </div>
            </div>

            <div class="row mb-0">
                <div class="col-md-8 offset-md-4">
                    <button type="submit" class="submit-btn">
                        {{ __('Login') }}
                    </button>
                    
                </div>
            </div>
        </form>
        <button class="google-btn">
          <img src="https://cdn.jsdelivr.net/gh/devicons/devicon/icons/google/google-original.svg" alt="Google logo" />
          Login with Google
        </button>
       {{--  @if (Route::has('password.request'))
            <div class="text-end" style="display: flex;justify-content: center;align-items: center; padding: 1rem;">
                <a class="btn btn-link mt-2" href="{{ route('password.request') }}">
                    {{ __('Forgot Your Password?') }}
                </a>
            </div>
        @endif --}}
      </div>
      <!-- Signup Form -->
      <div class="form-container" style="text-align:start;" id="signupForm">
        <form method="POST" action="{{ route('register') }}">
            @csrf
            <label for="name" class="col-md-4 col-form-label text-md-end">{{ __('Name') }}</label>
            <input id="name" type="text" class="form-control @error('name') is-invalid @enderror" placeholder="Enter Name" name="name" value="{{ old('name') }}" required autocomplete="name" autofocus>
            @error('name')
                <span class="invalid-feedback" role="alert">
                    <strong>{{ $message }}</strong>
                </span>
            @enderror
            <label for="email" class="col-md-4 col-form-label text-md-end">{{ __('Email Address') }}</label>
            <input id="email" type="email" class="form-control @error('email') is-invalid @enderror" name="email" placeholder="Enter Email" value="{{ old('email') }}" required autocomplete="email">
            @error('email')
                <span class="invalid-feedback" role="alert">
                    <strong>{{ $message }}</strong>
                </span>
            @enderror
            <label for="password" class="col-md-4 col-form-label text-md-end">{{ __('Password') }}</label>
            <input id="password" type="password" class="form-control @error('password') is-invalid @enderror" placeholder="Enter Password" name="password" required autocomplete="new-password">
            @error('password')
                <span class="invalid-feedback" role="alert">
                    <strong>{{ $message }}</strong>
                </span>
            @enderror
            <label for="password-confirm" class="col-md-4 col-form-label text-md-end">{{ __('Confirm Password') }}</label>
            <input id="password-confirm" type="password" class="form-control1" placeholder="Confirm Password" name="password_confirmation" required autocomplete="new-password">
            <div class="row mb-0">
                <div class="col-md-6 offset-md-4">
                    <button type="submit" class="submit-btn">
                        {{ __('Register') }}
                    </button>
                </div>
            </div>
        </form>
        <button class="google-btn">
          <img src="https://cdn.jsdelivr.net/gh/devicons/devicon/icons/google/google-original.svg" alt="Google logo" />
          Signup with Google
        </button>
      </div>
    </div>
  </div>

  <!-- Script to toggle forms -->
  <script>
    const loginToggle = document.getElementById('loginToggle');
    const signupToggle = document.getElementById('signupToggle');
    const loginForm = document.getElementById('loginForm');
    const signupForm = document.getElementById('signupForm');

    loginToggle.addEventListener('click', () => {
      loginToggle.classList.add('active');
      signupToggle.classList.remove('active');
      loginForm.classList.add('active');
      signupForm.classList.remove('active');
    });

    signupToggle.addEventListener('click', () => {
      signupToggle.classList.add('active');
      loginToggle.classList.remove('active');
      signupForm.classList.add('active');
      loginForm.classList.remove('active');
    });
  </script>
</body>
</html>



<div class="container d-none">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">{{ __('Login') }}</div>

                <div class="card-body">
                    <form method="POST" action="{{ route('login') }}">
                        @csrf

                        <div class="row mb-3">
                            <label for="email" class="col-md-4 col-form-label text-md-end">{{ __('Email Address') }}</label>

                            <div class="col-md-6">
                                <input id="email" type="email" class="form-control @error('email') is-invalid @enderror" name="email" value="{{ old('email') }}" required autocomplete="email" autofocus>

                                @error('email')
                                    <span class="invalid-feedback" role="alert">
                                        <strong>{{ $message }}</strong>
                                    </span>
                                @enderror
                            </div>
                        </div>

                        <div class="row mb-3">
                            <label for="password" class="col-md-4 col-form-label text-md-end">{{ __('Password') }}</label>

                            <div class="col-md-6">
                                <input id="password" type="password" class="form-control @error('password') is-invalid @enderror" name="password" required autocomplete="current-password">

                                @error('password')
                                    <span class="invalid-feedback" role="alert">
                                        <strong>{{ $message }}</strong>
                                    </span>
                                @enderror
                            </div>
                        </div>

                        <div class="row mb-3">
                            <div class="col-md-6 offset-md-4">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="remember" id="remember" {{ old('remember') ? 'checked' : '' }}>

                                    <label class="form-check-label" for="remember">
                                        {{ __('Remember Me') }}
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div class="row mb-0">
                            <div class="col-md-8 offset-md-4">
                                <button type="submit" class="btn btn-primary">
                                    {{ __('Login') }}
                                </button>

                                @if (Route::has('password.request'))
                                    <a class="btn btn-link" href="{{ route('password.request') }}">
                                        {{ __('Forgot Your Password?') }}
                                    </a>
                                @endif
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

