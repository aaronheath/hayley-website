<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <link rel="icon" type="image/svg+xml" href="favicon.svg" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />

    <link href="{{ asset('css/app.css') }}" rel="stylesheet">
    <title>Hayley O'Kelly | CV</title>

    <script src="https://kit.fontawesome.com/27422e76a0.js" crossorigin="anonymous"></script>
</head>
<body class="font-sans antialiased w-full">
<div class="container mx-auto max-w-screen-xl">
    <main id="wrapper"
        class="flex flex-col sm:flex-row-reverse sm:m-12 shadow-2xl">
        <div id="sidebar"
            class="w-full sm:max-w-sm p-8 bg-gradient-to-b from-purple-300 sm:from-purple-600 to-white text-black sm:text-white">
            <div class="px-2 mb-12">
{{--                <img--}}
{{--                    src="./assets/jack-finnigan-rriAI0nhcbc-unsplash.jpg"--}}
{{--                    alt="Profile Picture"--}}
{{--                    class="rounded-full w-48 mx-auto mb-2"--}}
{{--                />--}}
                <h1 class="text-center text-3xl font-semibold mb-2">Hayley O'Kelly</h1>
                <h2 class="text-center text-xl font-light">Office Administrator</h2>
            </div>

            <div class="font-light text-lg px-2 mb-12">
                <h2 class="text-xl font-semibold mb-4">Contact</h2>
                <a href="mailto:hayley@hayleyokelly.com" class="flex items-center my-3">
                    <i class="inline w-6 mr-2 fa-light fa-envelope"></i>
                    <span>hayley@hayleyokelly.com</span>
                </a>
                <div class="flex items-center my-3">
                    <i class="inline w-6 mr-2 fa-light fa-location-arrow"></i>
                    <div>
                        <p>Adelaide, Australia</p>
                    </div>
                </div>
            </div>

            <div class="font-light text-lg px-2 mb-12">
                <h2 class="text-xl font-semibold mb-4">Personal</h2>
                <div class="flex items-center my-3">
                    <i class="inline-block w-6 mr-2 fa-light fa-calendar-alt"></i>
                    <span>Born on 25th of April 1986</span>
                </div>
                <a href="{{ asset('storage/cv/hayley-okelly-resume-20230802-001.pdf') }}"
                   class="flex items-center my-3"
                   target="_blank">
                    <i class="inline-block w-6 mr-2 fa-light fa-cloud-download"></i>
                    <span>Download CV</span>
                </a>
            </div>

            <div class="font-light text-3xl px-2 mb-12">
                <h2 class="text-xl font-semibold mb-4">Networking</h2>
                <div class="flex items-center my-3">
                    <a href="https://www.linkedin.com/in/hayley-okelly"
                       target="_blank"><i class="fa-brands fa-linkedin"></i></a>
                </div>
            </div>
        </div>
        <div class="content w-full p-12">
            <div id="profile" class="prose">
                <h2 class="section-headline text-purple-600">Profile</h2>
                <p>
                    I have spent the last 18+ years developing a variety of skills that have served me well in diverse
                    working environments and sectors in Australia as well as in Canada. I am seeking a new opportunity
                    to utilise these skills. I also have a keen interest in Human Resource Management and I am currently
                    enrolled at UpSkilled  studying this subject.
                </p>
            </div>
            <hr class="mt-8 mb-12" />
            <div id="projects" class="prose">
                <h2 class="section-headline">Key Skills</h2>
                <div class="mb-16">
                    <h4 id="expand-knowledge-headline">
                        Communication
                    </h4>
                    <ul>
                        <li>Ability to answer the telephone in a professional manner</li>
                        <li>Able to communicate with people of all ages in a friendly manner</li>
                        <li>Excellent listening abilities</li>
                        <li>Excellent verbal skills</li>
                    </ul>

                    <h4 id="expand-knowledge-headline">
                        Customer Service
                    </h4>
                    <ul>
                        <li>Over 18 years experience with direct customer contact</li>
                        <li>Able to deal with difficult customers</li>
                        <li>Maintenance of retail space upkeep and presentation</li>
                        <li>Accurate cash handling skills</li>
                        <li>Able to efficiently and productively interact with team members</li>
                    </ul>
                </div>
            </div>
            <hr class="mt-8 mb-12" />
            <div id="experience" class="prose">
                <h2 class="section-headline">Experience</h2>
                <x-position tasks="Administration,Scheduling,Grant Applications,Debt Collection,Client Relations,Stock Control,Documentation">
                    <x-slot name="company">IT 4 Retirees</x-slot>

                    <x-slot name="when">Jun 2022 - Present</x-slot>

                    <x-slot name="role">
                        Office Administrator<br>
                        Adelaide, Australia
                    </x-slot>

                    <x-slot name="description">
                        <p>IT 4 Retirees is a South Australian family-owned business that offers group classes, 1 on 1
                            classes as well as remote classes to over 55’s to assist with all IT issues they encounter.</p>

                        <p>IT 4 Retirees has allowed me to build on the skills gained previously as well as helping me to expand and build on these skills.</p>

                        <p>My Duties include:</p>

                        <ul>
                            <li>Scheduling appointments and managing the calendars for 26 trainers in multiple
                                states.</li>
                            <li>Schedule group classes using government grants for community organisations.</li>
                            <li>Invoicing private clients as well as home care providers.</li>
                            <li>Debt collection of all outstanding invoices.</li>
                            <li>Reconciling trainer’s invoices with calendar appointments and invoiced services for
                                payroll.</li>
                            <li>Contacting clients; new, previous and clients who have expressed interest in
                                services.</li>
                            <li>Updating and maintaining CRM database with up-to-date information on all clients.</li>
                            <li>Update and manage the asset and stock database.</li>
                            <li>Update Admin manual as processes update.</li>
                            <li>Handle day to day enquiries via phone and email from general public, clients, and
                                providers.</li>
                        </ul>
                    </x-slot>
                </x-position>

                <x-position tasks="Customer Service,Shipping,EDMs,Support Guides,Training">
                    <x-slot name="company">DPS Publishing</x-slot>

                    <x-slot name="when">Nov 2019 - Jun 2022</x-slot>

                    <x-slot name="role">
                        Client Services Representative<br>
                        Adelaide, Australia
                    </x-slot>

                    <x-slot name="description">
                        <p>DPS Publishing is a South Australian owned company. DPS Publishing produces
                            The Aged Care and Disability Support Guides as both a printed guide and website. The
                            Guides provide vital information, advice and news for people navigating through the Aged
                            and Disability sectors.</p>

                        <p>DPS Publishing has seen me utilise the skills and experience gained during previous roles
                            to provide great experiences for advertisers and consumers.</p>

                        <p>My duties included:</p>

                        <ul>
                            <li>Cold calling non-advertising companies to update and verify their listings
                                details.</li>
                            <li>Liaising with advertisers to ensure the correct artwork is supplied for publications
                                and / or web advertisements.</li>
                            <li>Designing and creating EDMs for advertisers which are received by up to 15,000
                                subscribers.</li>
                            <li>Creating and maintaining our “how to” and support guides for customers using our
                                online portal.</li>
                            <li>Day to day handling of logistics associated with the distribution of thousands of
                                magazine publications and mail campaigns.</li>
                            <li>Onboarding and training of new customer service staff.</li>
                            <li>Handling day to day inbound enquiries from our customers via phone and email.</li>
                        </ul>
                    </x-slot>
                </x-position>

                <x-position tasks="Stock Management,Accounts,Contracts,Customer Service">
                    <x-slot name="company">Destination Slope & Surf Outfitters</x-slot>

                    <x-slot name="when">Nov 2017 - Sep 2019</x-slot>

                    <x-slot name="role">
                        Retail Supervisor<br>
                        Vancouver, Canada
                    </x-slot>

                    <x-slot name="description">
                        <p>The Destination Slope & Surf Outfitters is a family owned store selling snow and water sport
                            supplies to the North Shore of Vancouver, Canada.</p>

                        <p>I had the pleasure to be employed here for two years. During this time I was presented the
                            opportunity to upskill in various areas of the business ranging stock management through to
                            becoming an expert in winter sports equipment.</p>

                        <p>My day to day role was to assist in the invoicing, contract paperwork, payments and ordering
                            stock whilst also assisting customers (in person and on the phone) with varying knowledge
                            and skills. </p>
                    </x-slot>
                </x-position>

                <x-position tasks="Accounts,Stock Management,Rosters,Training">
                    <x-slot name="company">Carlbergs Giftshop</x-slot>

                    <x-slot name="when">May 2017 - Oct 2017</x-slot>

                    <x-slot name="role">
                        Sales Lead Hand<br>
                        Whistler, Canada
                    </x-slot>

                    <x-slot name="description">
                        <p>Carlbergs Giftshop was a fun and happy work environment where I enjoyed putting skills gained
                            previously to use.</p>

                        <p>The duties expected of me were as diverse as the people I worked with and the people I
                            served. </p>

                        <p>These duties included reconciling invoices with stock, managing new and existing suppliers
                            and products in the stock control system, assisting to manage and adjust the roster, opening
                            and closing the store and being trusted with the security of the store, warehouse and
                            vehicles by way of being issued my own set of  keys and alarm codes. </p>

                        <p>I also assisted with stocktake counting and reconciling in the system, balancing cash
                            registers and preparing for banking. </p>

                        <p>A large part of my role was to assist in the hiring, and training of new team members as well
                            as upskilling existing staff members in new procedures and to step up into Lead Hand
                            roles.</p>
                    </x-slot>
                </x-position>

                <x-position tasks="Onboard">
                    <x-slot name="company">Whistler Blackcomb</x-slot>

                    <x-slot name="when">Sep 2015 - Apr 2017</x-slot>

                    <x-slot name="role">
                        EE Representative<br>
                        Whistler, Canada
                    </x-slot>

                    <x-slot name="description">
                        <p>As an Employee Experience Representative at Whistler Blackcomb I assisted new staff to
                            finalise their work requirements such that they were able to being their positions on time,
                            fully equipt, in correct uniform and with all paperwork correctly completed and lodged.</p>

                        <p>Behind the scenes we assisted with the design, and ordering of  new uniforms for the various
                            departments. </p>

                        <p>I was able to utilise and build upon the relationships I established with Staff Members from
                            all different departments  as a Lift Host to install a positive and inclusive work
                            environment.</p>
                    </x-slot>
                </x-position>

                <x-position tasks="Customer Service,Health & Safety">
                    <x-slot name="company">Whistler Blackcomb</x-slot>

                    <x-slot name="when">Mar 2014 - Sep 2015</x-slot>

                    <x-slot name="role">
                        Lift Host<br>
                        Whistler, Canada
                    </x-slot>

                    <x-slot name="description">
                        <p>Working on Whistler Mountain as a Lift Host was a once in a lifetime opportunity. It enabled
                            me to push myself out of my comfort zone and to broaden my horizons. </p>

                        <p>The role was a balancing act between ensuring guests had fun and enjoyed their experience
                            while ensuring their safety and the integrity of the lifts.</p>

                        <p>The primary of a Lift Host is to liaise with the maintenance department ensuring all
                            equipment functioned correctly and the lifts run smoothly.</p>

                        <p>In the summer of 2015 I was made Lead Hand at the WestCoaster Slide. Being Lead Hand meant
                            that I was responsible for training new Staff on operating the Slide, as well as ensuring
                            that all guests understood safety procedures. Safety was always of primary importance.</p>

                        <p>The Summer of 2015 was filled with challenges that led to my being awarded the title of Lead
                            Hand of the summer.</p>

                        <p>In this highly dynamic role I was also able to enhance my customer service skills whilst also
                            helping to instil the necessary work ethic onto staff from all over the world.</p>
                    </x-slot>
                </x-position>

                <x-position tasks="Stock Management">
                    <x-slot name="company">Coles Supermarket</x-slot>

                    <x-slot name="when">Feb 2012 - Jan 2014</x-slot>

                    <x-slot name="role">
                        Bakery In Charge<br>
                        Adelaide, Australia
                    </x-slot>

                    <x-slot name="description">
                        <p>In my role as Bakery In-Charge at Coles I was tasked with managing day to day operations of
                            the bakery unit. During this time I also co-managed both Meat and Deli departments.</p>

                        <p>Key attention was required as goods sold in these departments often had a short shelf life.
                            For the safety of our customers it was important that we ensured the timely removal of
                            expired items.</p>

                        <p>Furthermore, it was my responsibility to manage stock rotation, stock ordering and the
                            minimising of wasted stock.</p>

                        <p>It was in my time managing the department, that for the first time in the store's history the
                            bakery department's waste was below projection and was making a profit.</p>
                    </x-slot>
                </x-position>

                <x-position tasks="Rosters,Customer Service,Retail Presentation">
                    <x-slot name="company">Coles Supermarket</x-slot>

                    <x-slot name="when">June  2007 -Feb 2012</x-slot>

                    <x-slot name="role">
                        Service Second In Charge<br>
                        Adelaide, Australia
                    </x-slot>

                    <x-slot name="description">
                        <p>In this role I was tasked with creating and managing rosters for up to 25 staff which was a
                            complex process due to varying availabilities and skill requirements.</p>

                        <p>I was also responsible for ensuring tills were correctly balanced, legal requirements were
                            met, story tidiness, handling refunds and addressing difficult customers.</p>
                    </x-slot>
                </x-position>

                <x-position tasks="Customer Service">
                    <x-slot name="company">Bi-Lo Supermarket</x-slot>

                    <x-slot name="when">Mar 2005 - June 2007</x-slot>

                    <x-slot name="role">
                        Service Assistant<br>
                        Adelaide, Australia
                    </x-slot>

                    <x-slot name="description">
                        <p>This was an entry level position as a checkout operator in which I was responsible for
                            ensuring the correctness of my tills balance and also providing customers with a pleasurable
                            experience whilst finalising their purchases.</p>
                    </x-slot>
                </x-position>

                <x-position tasks="Customer Service,Account,Rosters">
                    <x-slot name="company">Goode Solutions</x-slot>

                    <x-slot name="when">June 2004 - Dec 2004</x-slot>

                    <x-slot name="role">
                        Receptionist<br>
                        Adelaide, Australia
                    </x-slot>

                    <x-slot name="description">
                        <p>Goode Solutions was a small South Australian Owned Telecommunication company where I occupied
                            an entry receptionist role where I prepared invoices, organised quotes and planned
                            technicians schedules.</p>
                    </x-slot>
                </x-position>
            </div>
        </div>
    </main>
</div>

</body>
</html>
