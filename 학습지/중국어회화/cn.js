document.addEventListener("DOMContentLoaded", function () {
	// 1. 페이지 번호 자동 계산 기능
	const allPages = document.querySelectorAll(".page");
	const totalPages = allPages.length;

	allPages.forEach((page, index) => {
		const pageNum = index + 1;
		const footer = page.querySelector(".footer");
		if (footer) {
			const label = footer.getAttribute("data-label") || "";
			footer.textContent = `semo.${pageNum} `;
		}
	});
});


function toggleAnswers(button) {
    const page = button.closest('.page');
    const answers = page.querySelectorAll('.quiz-answer');

    let isShowing = false;

    answers.forEach((answer) => {
        answer.classList.toggle('show');

        if (answer.classList.contains('show')) {
            isShowing = true;
        }
    });

    button.textContent = isShowing ? '△' : '▽';
}
