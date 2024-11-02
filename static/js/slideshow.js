function initializeSlideshow() {
  // Set index and transition delay
  let index = 0;
  const transitionDelay = 10000;

  // Get div containing the slides
  const slideContainer = document.querySelector(".slideshow");
  const contentContainer = document.querySelector(".content"); // Assuming you have a content div

  // Array of images and captions
  const basePath = "/static/img/slideshow"
  const slidesData = [
    { img: "/free_wifi.png" },
    { img: "/segundo_piso.png" },
    { img: "/tarjeta_regalo.png" },
  ];

  // Create slides dynamically
  slidesData.forEach(slideData => {
    const slide = document.createElement("div");
    slide.classList.add("slide");

    const img = document.createElement("img");
    img.src = basePath + slideData.img;
    // img.alt = slideData.caption;

    // const p = document.createElement("p");
    // p.textContent = slideData.caption;

    slide.appendChild(img);
    // slide.appendChild(p);
    slideContainer.appendChild(slide);
  });

  // Get the slides
  const slides = slideContainer.querySelectorAll(".slide");

  // Set transition delay for slides
  slides.forEach(slide => {
    slide.style.transition = `all ${transitionDelay / 1000}s linear`;
  });

  // Show the first slide
  showSlide(index);

  // Show a specific slide
  function showSlide(slideNumber) {
    slides.forEach((slide, i) => {
      slide.style.display = i === slideNumber ? "block" : "none";
    });
    // Next index
    index++;
    // Go back to 0 if at the end of slides
    if (index >= slides.length) {
      index = 0;
    }
  }

  // Transition to next slide every x seconds
  setInterval(() => showSlide(index), transitionDelay);

  // Apply CSS styles
  applyStyles();

}

// Function to add CSS styles dynamically
function applyStyles() {
  const style = document.createElement("style");
  style.textContent = `
    html, body {
      margin: 0;
      height: 100%;
      overflow: hidden; /* Prevent scrolling */
    }

    .slideshow {
      position: fixed; /* Cover the entire window */
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      display: flex;
      justify-content: center;
      align-items: center;
      background-color: black; /* Optional: Set a background color */
      z-index: 1; /* Ensure it sits on top */
    }

    .slide {
      display:none;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      color: white; /* Optional: Change text color */
    }

    .slide img {
      max-width: 100%;
      max-height: 100%;
      object-fit: cover; /* Ensure images cover the slide area */
    }
  `;
  document.head.appendChild(style);
  const slideContainer = document.querySelector(".slideshow");

  slideContainer.style.display = "flex";
}

// Function to reset the state
function resetToOriginalState() {
  const slideContainer = document.querySelector(".slideshow");

  // Hide the slideshow
  slideContainer.style.display = "none";


}

// Call the function to initialize the slideshow
// initializeSlideshow();
